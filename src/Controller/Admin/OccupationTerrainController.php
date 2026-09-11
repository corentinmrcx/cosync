<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\CreneauOccupation;
use App\Entity\EspaceTerrain;
use App\Entity\Season;
use App\Enum\JourSemaine;
use App\Enum\Permission;
use App\Enum\UsageOccupation;
use App\Repository\EspaceTerrainRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\Drive\OccupationDriveSync;
use App\Service\Occupation\CreneauOccupationFactory;
use App\Service\Occupation\CreneauOccupationService;
use App\Service\Occupation\EspaceTerrainService;
use App\Service\Occupation\OccupationGrillePresenter;
use App\Service\Pdf\OccupationPdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Occupation des terrains : la semaine type que le club remet à la mairie.
 *
 * Les droits sont ceux du planning des matchs, volontairement : les deux écrans sont tenus
 * par la même personne au club, et une permission de plus qui ne sépare aucune fonction
 * n'alourdirait que l'écran des rôles.
 *
 * Une grille appartient à une saison — d'où `#[CurrentSeason]` —, mais le référentiel des
 * terrains n'en dépend pas : un terrain ne change pas de nom au 1ᵉʳ juillet.
 */
#[Route('/admin/outils/occupation-terrains', name: 'admin_occupation_')]
#[IsGranted(Permission::PLANNING_LIRE->value)]
class OccupationTerrainController extends AbstractController
{
    public function __construct(
        private readonly CreneauOccupationService $creneaux,
        private readonly CreneauOccupationFactory $factory,
        private readonly OccupationGrillePresenter $presenter,
        private readonly EspaceTerrainService $terrains,
        private readonly EspaceTerrainRepository $terrainRepo,
        private readonly TeamRepository $teamRepo,
        private readonly OccupationPdfService $pdf,
        private readonly OccupationDriveSync $driveSync,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        return $this->render('admin/outils/occupation/index.html.twig', [
            'season' => $season,
            'grille' => $this->presenter->grille($season),
            'creneaux' => $this->creneaux->listerPourAdmin($season),
            'heuresParTerrain' => $this->presenter->heuresParTerrain($season),
            'equipes' => $this->teamRepo->findBySeason($season),
            'terrains' => $this->terrainRepo->findActifs(),
            'jours' => JourSemaine::cases(),
            'usages' => UsageOccupation::cases(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function new(#[CurrentSeason] Season $season, Request $request): Response
    {
        $this->csrf->valider('occupation_creneau', $request);

        try {
            $this->creneaux->creer($this->factory->depuisRequest($request), $season);
            $this->addFlash('success', 'Créneau ajouté à la grille.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_index');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function edit(CreneauOccupation $creneau, Request $request): Response
    {
        $this->csrf->valider('occupation_creneau', $request);

        try {
            $this->creneaux->modifier($creneau, $this->factory->depuisRequest($request));
            $this->addFlash('success', 'Créneau mis à jour.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function delete(CreneauOccupation $creneau, Request $request): Response
    {
        $this->csrf->valider('occupation_supprimer_' . $creneau->getId(), $request);

        $this->creneaux->supprimer($creneau);
        $this->addFlash('success', 'Créneau retiré de la grille.');

        return $this->redirectToRoute('admin_occupation_index');
    }

    /** Le geste de début de saison : sans lui, tout serait à ressaisir chaque 1ᵉʳ juillet. */
    #[Route('/reprendre', name: 'reprise', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function reprise(#[CurrentSeason] Season $season, Request $request): Response
    {
        $this->csrf->valider('occupation_reprise', $request);

        try {
            $this->addFlash('success', $this->creneaux->reprendreLaSaisonPrecedente($season)->resume());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_index');
    }

    /* ── Référentiel des terrains ── */

    #[Route('/terrains', name: 'terrains', methods: ['GET'])]
    public function terrains(): Response
    {
        return $this->render('admin/outils/occupation/terrains.html.twig', $this->terrains->referentiel());
    }

    #[Route('/terrains/nouveau', name: 'terrain_new', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function terrainNew(Request $request): Response
    {
        $this->csrf->valider('occupation_terrain_nouveau', $request);

        try {
            $espace = $this->terrains->creer((string) $request->request->get('nom', ''));
            $this->addFlash('success', sprintf('Terrain « %s » ajouté.', $espace->getNom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_terrains');
    }

    #[Route('/terrains/{id}/renommer', name: 'terrain_rename', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function terrainRename(EspaceTerrain $espace, Request $request): Response
    {
        $this->csrf->valider('occupation_terrain_' . $espace->getId(), $request);

        try {
            $this->terrains->renommer($espace, (string) $request->request->get('nom', ''));
            $this->addFlash('success', sprintf('Terrain renommé en « %s ».', $espace->getNom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_terrains');
    }

    #[Route('/terrains/{id}/service', name: 'terrain_toggle', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function terrainToggle(EspaceTerrain $espace, Request $request): Response
    {
        $this->csrf->valider('occupation_terrain_' . $espace->getId(), $request);

        $this->terrains->basculerActif($espace);
        $this->addFlash('success', $espace->isActif()
            ? sprintf('« %s » est de nouveau proposé à la réservation.', $espace->getNom())
            : sprintf('« %s » est retiré du service : il ne sera plus proposé, les créneaux existants le nomment toujours.', $espace->getNom()));

        return $this->redirectToRoute('admin_occupation_terrains');
    }

    #[Route('/terrains/{id}/supprimer', name: 'terrain_delete', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function terrainDelete(EspaceTerrain $espace, Request $request): Response
    {
        $this->csrf->valider('occupation_terrain_' . $espace->getId(), $request);

        $nom = $espace->getNom();

        try {
            $this->terrains->supprimer($espace);
            $this->addFlash('success', sprintf('Terrain « %s » supprimé.', $nom));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_occupation_terrains');
    }

    /* ── Document ── */

    #[Route('/generer', name: 'generate', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function generate(#[CurrentSeason] Season $season, Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('admin/outils/occupation/generer.html.twig', [
                'season' => $season,
                'grille' => $this->presenter->grille($season),
                'incomplets' => $this->presenter->creneauxIncomplets($season),
            ]);
        }

        $this->csrf->valider('occupation_generer', $request);

        $contenu = $this->pdf->rendu($season);
        $nomFichier = $this->pdf->nomFichier($season);

        if ($request->request->getBoolean('archiver')) {
            $archive = $this->driveSync->archiver($contenu, $nomFichier, $season);

            // L'échec est dit : croire le document archivé alors qu'il ne l'est pas est
            // pire que de ne pas l'archiver.
            $this->addFlash($archive ? 'success' : 'error', $archive
                ? 'Grille archivée sur le Drive du club.'
                : 'Grille générée, mais l\'archivage Drive a échoué. Réessayez depuis cet écran.');
        }

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nomFichier),
        ]);
    }
}

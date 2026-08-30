<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\TeamEditData;
use App\DTO\TeamSetupData;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\Permission;
use App\Form\TeamSetupType;
use App\Repository\CategoryRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\Licencie\AffectationEquipeService;
use App\Service\Referentiel\TeamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/saison/equipes', name: 'admin_equipes_')]
#[IsGranted(Permission::SAISON_LIRE->value)]
class EquipeController extends AbstractController
{
    public function __construct(
        private readonly TeamService $teamService,
        private readonly TeamRepository $teamRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly AffectationEquipeService $affectationService,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentSeason] Season $season,
    ): Response {
        return $this->render('admin/saison/equipes.html.twig', [
            'season' => $season,
            'teams' => $this->teamRepo->findBySeason($season),
            'newTeamForm' => $this->createForm(TeamSetupType::class, new TeamSetupData(), [
                'action' => $this->generateUrl('admin_equipes_new'),
            ]),
            'categories' => $this->categoryRepo->findAllOrdered(),
            'affectation' => $this->affectationService->apercu($season),
        ]);
    }

    #[Route('/affectation-automatique', name: 'affectation_auto', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function affectationAutomatique(
        #[CurrentSeason] Season $season,
        Request $request,
    ): Response {
        $this->csrf->valider('affectation_auto_equipes', $request);

        $resultat = $this->affectationService->appliquer($season);

        if ($resultat->total() === 0) {
            $this->addFlash('info', 'Aucun licencié à affecter : tous ont déjà une équipe, ou aucune équipe ne couvre leur catégorie.');

            return $this->redirectToRoute('admin_equipes_index');
        }

        $detail = [];
        foreach ($resultat->parEquipe as $nom => $nombre) {
            $detail[] = sprintf('%s (%d)', $nom, $nombre);
        }

        $this->addFlash('success', sprintf(
            '%d licencié%s affecté%s — %s.',
            $resultat->total(),
            $resultat->total() > 1 ? 's' : '',
            $resultat->total() > 1 ? 's' : '',
            implode(', ', $detail),
        ));

        return $this->redirectToRoute('admin_equipes_index');
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function new(
        #[CurrentSeason] Season $season,
        Request $request,
    ): Response {
        $form = $this->createForm(TeamSetupType::class, $data = new TeamSetupData());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Le nom de l\'équipe est obligatoire.');

            return $this->redirectToRoute('admin_equipes_index');
        }

        try {
            $team = $this->teamService->creer($data, $season);
            $this->addFlash('success', sprintf('Équipe "%s" créée.', $team->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_equipes_index');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function edit(Team $team, Request $request): Response
    {
        $this->csrf->valider('edit_team_' . $team->getId(), $request);

        try {
            $this->teamService->mettreAJour($team, TeamEditData::fromRequest($request));
            $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $team->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_equipes_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function delete(Team $team, Request $request): Response
    {
        $this->csrf->valider('delete_team_' . $team->getId(), $request);

        $name = $team->getName();
        $this->teamService->supprimer($team);
        $this->addFlash('success', sprintf('Équipe "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_equipes_index');
    }
}

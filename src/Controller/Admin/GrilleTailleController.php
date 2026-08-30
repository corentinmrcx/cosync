<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GrilleTaille;
use App\Entity\GrilleTailleValeur;
use App\Enum\Permission;
use App\Enum\TailleType;
use App\Repository\GrilleTailleRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\GrilleTailleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Grilles de tailles : la traduction entre ce qu'une personne déclare et ce que le fournisseur
 * étiquette. Référentiel niveau club, comme les tailles elles-mêmes.
 */
#[Route('/admin/club/grilles-tailles', name: 'admin_grilles_')]
#[IsGranted(Permission::STOCK_LIRE->value)]
class GrilleTailleController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly GrilleTailleService $grilleService,
        private readonly GrilleTailleRepository $repository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/club/grilles/list.html.twig', [
            'grilles' => $this->repository->findAllOrdered(),
            'articlesParGrille' => $this->repository->compterArticlesParGrille(),
            'types' => TailleType::cases(),
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function new(Request $request): Response
    {
        $this->csrf->valider('grille_nouvelle', $request);

        try {
            $grille = $this->grilleService->creer(
                (string) $request->request->get('nom', ''),
                TailleType::tryFrom((string) $request->request->get('type', '')) ?? TailleType::VETEMENT,
            );
            $this->addFlash('success', sprintf('Grille « %s » créée.', $grille->getNom()));

            // Une grille vide ne sert à rien : on ouvre directement là où on la remplit.
            return $this->redirectToRoute('admin_grilles_show', ['id' => $grille->getId()]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_grilles_index');
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(GrilleTaille $grille): Response
    {
        return $this->render('admin/club/grilles/form.html.twig', [
            'grille' => $grille,
            'cibles' => $this->grilleService->ciblesPossibles($grille->getType()),
            'declarables' => $this->grilleService->declarables($grille->getType()),
            'nonCouvertes' => $this->grilleService->taillesNonCouvertes($grille),
        ]);
    }

    #[Route('/{id}/renommer', name: 'rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function rename(GrilleTaille $grille, Request $request): Response
    {
        $this->csrf->valider('grille_renommer_' . $grille->getId(), $request);

        try {
            $this->grilleService->renommer($grille, (string) $request->request->get('nom', ''));
            $this->addFlash('success', 'Grille renommée.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_grilles_show', ['id' => $grille->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function delete(GrilleTaille $grille, Request $request): Response
    {
        $this->csrf->valider('grille_supprimer_' . $grille->getId(), $request);

        $nom = $grille->getNom();

        try {
            $this->grilleService->supprimer($grille);
            $this->addFlash('success', sprintf('Grille « %s » supprimée.', $nom));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_grilles_index');
    }

    #[Route('/{id}/valeurs', name: 'valeur_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function valeurAdd(GrilleTaille $grille, Request $request): Response
    {
        $this->csrf->valider('grille_valeur_ajouter_' . $grille->getId(), $request);

        try {
            $this->grilleService->ajouterValeur($grille, $this->cibleId($request), $this->couvertureIds($request));
            $this->addFlash('success', 'Ligne ajoutée à la grille.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_grilles_show', ['id' => $grille->getId()]);
    }

    #[Route('/valeurs/{id}/modifier', name: 'valeur_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function valeurEdit(GrilleTailleValeur $valeur, Request $request): Response
    {
        $this->csrf->valider('grille_valeur_modifier_' . $valeur->getId(), $request);

        try {
            $this->grilleService->modifierValeur($valeur, $this->couvertureIds($request));
            $this->addFlash('success', sprintf('« %s » mise à jour.', $valeur->getCible()->getLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_grilles_show', ['id' => $valeur->getGrille()->getId()]);
    }

    #[Route('/valeurs/{id}/supprimer', name: 'valeur_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function valeurDelete(GrilleTailleValeur $valeur, Request $request): Response
    {
        $this->csrf->valider('grille_valeur_supprimer_' . $valeur->getId(), $request);

        $grilleId = $valeur->getGrille()->getId();
        $libelle = $valeur->getCible()->getLibelle();

        $this->grilleService->supprimerValeur($valeur);
        $this->addFlash('success', sprintf('« %s » retirée de la grille.', $libelle));

        return $this->redirectToRoute('admin_grilles_show', ['id' => $grilleId]);
    }

    private function cibleId(Request $request): ?int
    {
        $id = (string) $request->request->get('cible', '');

        return $id === '' ? null : (int) $id;
    }

    /** @return int[] */
    private function couvertureIds(Request $request): array
    {
        return array_map('intval', (array) $request->request->all('couvertures'));
    }
}

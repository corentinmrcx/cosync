<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fonction;
use App\Enum\Permission;
use App\Repository\FonctionRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\FonctionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Référentiel des fonctions du club — ce que chacun fait, tel qu'on le déclare à la mairie
 * ou au conseil d'administration. Distinct du rôle du dirigeant, qui dit ce que
 * l'application lui doit ({@see \App\Enum\DirigeantRole}).
 */
#[Route('/admin/club/fonctions', name: 'admin_fonctions_')]
#[IsGranted(Permission::CLUB_REFERENTIELS->value)]
class FonctionController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly FonctionService $fonctionService,
        private readonly FonctionRepository $repository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/club/fonctions.html.twig', [
            'fonctions' => $this->repository->findAllOrdered(),
            'utilisations' => $this->repository->utilisations(),
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $this->csrf->valider('fonction_nouvelle', $request);

        try {
            $fonction = $this->fonctionService->creer(
                (string) $request->request->get('libelle', ''),
                $request->request->getBoolean('porte_equipe'),
            );
            $this->addFlash('success', sprintf('Fonction « %s » ajoutée.', $fonction->getLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_fonctions_index');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    public function edit(Fonction $fonction, Request $request): Response
    {
        $this->csrf->valider('fonction_modifier_' . $fonction->getId(), $request);

        try {
            $this->fonctionService->modifier(
                $fonction,
                (string) $request->request->get('libelle', ''),
                $request->request->getBoolean('porte_equipe'),
            );
            $this->addFlash('success', sprintf('Fonction « %s » mise à jour.', $fonction->getLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_fonctions_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Fonction $fonction, Request $request): Response
    {
        $this->csrf->valider('fonction_supprimer_' . $fonction->getId(), $request);

        $libelle = $fonction->getLibelle();

        try {
            $this->fonctionService->supprimer($fonction);
            $this->addFlash('success', sprintf('Fonction « %s » supprimée.', $libelle));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_fonctions_index');
    }

    /** Nouvel ordre reçu du glisser-déposer : la liste complète des identifiants, de haut en bas. */
    #[Route('/reordonner', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $this->csrf->valider('fonctions_reorder', $request);

        $this->fonctionService->reordonner(array_map('intval', (array) $request->request->all('ordre')));
        $this->addFlash('success', 'Ordre des fonctions enregistré.');

        return $this->redirectToRoute('admin_fonctions_index');
    }
}

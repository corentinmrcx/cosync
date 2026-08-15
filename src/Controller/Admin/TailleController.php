<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Taille;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\TailleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référentiel des tailles du club. Il sert deux publics : les formulaires, où l'on ne
 * propose que ce qu'une personne sait dire d'elle-même, et le stock, qui range aussi ce que
 * le fournisseur étiquette sur le carton.
 */
#[Route('/admin/club/tailles', name: 'admin_tailles_')]
class TailleController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly TailleService $tailleService,
        private readonly TailleRepository $repository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tailles = $this->repository->findAllOrdered();
        $employes = $this->tailleService->libellesEmployes();

        return $this->render('admin/club/tailles/list.html.twig', [
            'tailles' => $tailles,
            // Employée ou non : c'est ce qui verrouille le libellé et interdit la suppression.
            'employees' => array_map(
                static fn (Taille $t): bool => isset($employes[$t->getLibelle()]),
                array_combine(array_map(static fn (Taille $t): int => $t->getId(), $tailles), $tailles),
            ),
            'types' => TailleType::cases(),
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $this->csrf->valider('taille_nouvelle', $request);

        $taille = (new Taille())
            ->setLibelle((string) $request->request->get('libelle', ''))
            ->setType(TailleType::tryFrom((string) $request->request->get('type', '')) ?? TailleType::VETEMENT)
            ->setGroupe(trim((string) $request->request->get('groupe', '')) ?: null)
            ->setProposeeAuxLicencies($request->request->getBoolean('proposee'));

        try {
            $this->tailleService->creer($taille);
            $this->addFlash('success', sprintf('Taille « %s » ajoutée.', $taille->getLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_tailles_index');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    public function edit(Taille $taille, Request $request): Response
    {
        $this->csrf->valider('taille_modifier_' . $taille->getId(), $request);

        try {
            $this->tailleService->modifier(
                $taille,
                (string) $request->request->get('libelle', ''),
                (string) $request->request->get('groupe', ''),
                $request->request->getBoolean('proposee'),
            );
            $this->addFlash('success', sprintf('Taille « %s » mise à jour.', $taille->getLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_tailles_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Taille $taille, Request $request): Response
    {
        $this->csrf->valider('taille_supprimer_' . $taille->getId(), $request);

        $libelle = $taille->getLibelle();

        try {
            $this->tailleService->supprimer($taille);
            $this->addFlash('success', sprintf('Taille « %s » supprimée.', $libelle));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_tailles_index');
    }

    /** Nouvel ordre reçu du glisser-déposer : la liste complète des identifiants, de haut en bas. */
    #[Route('/reordonner', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $this->csrf->valider('tailles_reorder', $request);

        $this->tailleService->reordonner(array_map('intval', (array) $request->request->all('ordre')));
        $this->addFlash('success', 'Ordre des tailles enregistré.');

        return $this->redirectToRoute('admin_tailles_index');
    }
}

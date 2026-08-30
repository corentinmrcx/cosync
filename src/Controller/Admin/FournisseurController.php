<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\FournisseurData;
use App\Entity\Fournisseur;
use App\Enum\Permission;
use App\Repository\FournisseurRepository;
use App\Security\CsrfGuard;
use App\Service\Stock\FournisseurService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/stock/fournisseurs', name: 'admin_stock_fournisseurs_')]
#[IsGranted(Permission::STOCK_LIRE->value)]
class FournisseurController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly FournisseurService $fournisseurService,
        private readonly FournisseurRepository $fournisseurRepository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/stock/fournisseurs/list.html.twig', [
            'fournisseurs' => $this->fournisseurRepository->findAllOrdered(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function new(Request $request): Response
    {
        $this->csrf->valider('fournisseur_new', $request);

        try {
            $fournisseur = $this->fournisseurService->creer(FournisseurData::fromRequest($request));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_fournisseurs_list');
        }

        $this->addFlash('success', sprintf('Fournisseur "%s" créé.', $fournisseur->getNom()));

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function edit(Fournisseur $fournisseur, Request $request): Response
    {
        $this->csrf->valider('fournisseur_edit_' . $fournisseur->getId(), $request);

        $this->fournisseurService->mettreAJour($fournisseur, FournisseurData::fromRequest($request));
        $this->addFlash('success', 'Fournisseur mis à jour.');

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function delete(Fournisseur $fournisseur, Request $request): Response
    {
        $this->csrf->valider('fournisseur_delete_' . $fournisseur->getId(), $request);

        $nom = $fournisseur->getNom();
        $this->fournisseurService->supprimer($fournisseur);
        $this->addFlash('success', sprintf('Fournisseur "%s" supprimé.', $nom));

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }
}

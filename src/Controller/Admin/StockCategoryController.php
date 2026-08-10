<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\StockCategory;
use App\Form\StockCategoryType;
use App\Repository\StockCategoryRepository;
use App\Security\CsrfGuard;
use App\Service\Stock\StockCategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/stock/categories', name: 'admin_stock_categories_')]
class StockCategoryController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly StockCategoryService $categoryService,
        private readonly StockCategoryRepository $categoryRepository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/stock/categories/list.html.twig', [
            'categories' => $this->categoryRepository->findAllOrderedByPosition(),
            'newCategoryForm' => $this->createForm(StockCategoryType::class, new StockCategory(), [
                'action' => $this->generateUrl('admin_stock_categories_new'),
            ]),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $category = new StockCategory();
        $form = $this->createForm(StockCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryService->creer($category);
            $this->addFlash('success', sprintf('Catégorie "%s" créée.', $category->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_stock_categories_list');
    }

    /** Nouvel ordre reçu du glisser-déposer : la liste complète des identifiants, de haut en bas. */
    #[Route('/reordonner', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $this->csrf->valider('stock_categories_reorder', $request);

        $ids = array_map('intval', (array) $request->request->all('ordre'));
        $this->categoryService->reordonner($ids);
        $this->addFlash('success', 'Ordre des catégories enregistré.');

        return $this->redirectToRoute('admin_stock_categories_list');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['POST'])]
    public function edit(StockCategory $category, Request $request): Response
    {
        $form = $this->createForm(StockCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryService->enregistrer($category);
            $this->addFlash('success', sprintf('Catégorie "%s" mise à jour.', $category->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_stock_categories_list');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(StockCategory $category, Request $request): Response
    {
        $this->csrf->valider('delete_category_' . $category->getId(), $request);

        $name = $category->getName();
        $this->categoryService->supprimer($category);
        $this->addFlash('success', sprintf('Catégorie "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_stock_categories_list');
    }
}

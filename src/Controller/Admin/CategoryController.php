<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\CategoryCreateData;
use App\Entity\Category;
use App\Form\CategoryCreateType;
use App\Repository\CategoryRepository;
use App\Repository\LicencieRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\CategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/club/categories-fff', name: 'admin_categories_')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categoryRepo,
        private readonly LicencieRepository $licencieRepo,
        private readonly CategoryService $categoryService,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(CategoryCreateType::class, new CategoryCreateData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $category = $this->categoryService->create($form->getData());
                $this->addFlash('success', sprintf('Catégorie "%s" ajoutée.', $category->getCode()));
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_categories_index');
        }

        $categories = $this->categoryRepo->findAllOrdered();
        $counts = [];
        foreach ($categories as $cat) {
            $counts[$cat->getId()] = $this->licencieRepo->countByCategory($cat);
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories,
            'counts' => $counts,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Category $category, Request $request): Response
    {
        $this->csrf->valider('delete_category_' . $category->getId(), $request);

        try {
            $this->categoryService->delete($category);
            $this->addFlash('success', sprintf('Catégorie "%s" supprimée.', $category->getCode()));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_categories_index');
    }
}

<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\StockCategory;
use App\Entity\StockItem;
use App\Enum\LicenceStatus;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Form\StockCategoryType;
use App\Form\StockItemType;
use App\Repository\LicencieRepository;
use App\Repository\StockCategoryRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\SeasonContext;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/stock', name: 'admin_stock_')]
class StockController extends AbstractController
{
    private const PER_PAGE = 25;

    private const TAILLES_EQUIPEMENT = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '6 ans', '8 ans', '10 ans', '12 ans', '14 ans', '16 ans'];
    private const CONTENANCES_EPICERIE = ['25cl', '33cl', '50cl', '75cl', '1L', '1,5L', '2L'];

    public function __construct(
        private readonly StockService $stockService,
        private readonly StockItemRepository $itemRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockCategoryRepository $categoryRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly SeasonContext $seasonContext,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            $this->addFlash('warning', 'Créez une saison avant d\'accéder au stock.');
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/stock/dashboard.html.twig', [
            'summary'              => $this->stockService->getStockSummary($season),
            'season'               => $season,
            'licenciesValides'     => $this->licencieRepository->findValidatedBySeason($season),
            'types'                => StockMovementType::cases(),
            'sources'              => StockMovementSource::cases(),
        ]);
    }

    #[Route('/items/nouveau', name: 'items_new', methods: ['GET', 'POST'])]
    public function itemNew(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $item = new StockItem();
        $item->setSeason($season);
        $form = $this->createForm(StockItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyManualFields($item, $request);
            $this->em->persist($item);
            $this->em->flush();
            $this->addFlash('success', sprintf('Article "%s" créé.', $item->getNom()));
            return $this->redirectToRoute('admin_stock_dashboard');
        }

        return $this->render('admin/stock/items/form.html.twig', ['form' => $form] + $this->itemFormContext(null));
    }

    #[Route('/items/{id}/modifier', name: 'items_edit', methods: ['GET', 'POST'])]
    public function itemEdit(StockItem $item, Request $request): Response
    {
        $form = $this->createForm(StockItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyManualFields($item, $request);
            $this->em->flush();
            $this->addFlash('success', sprintf('Article "%s" mis à jour.', $item->getNom()));
            return $this->redirectToRoute('admin_stock_dashboard');
        }

        return $this->render('admin/stock/items/form.html.twig', ['form' => $form] + $this->itemFormContext($item));
    }

    /** @return array<string, mixed> */
    private function itemFormContext(?StockItem $item): array
    {
        return [
            'item'          => $item,
            'title'         => $item ? 'Modifier ' . $item->getNom() : 'Nouvel article',
            'kinds'         => StockItemKind::cases(),
            'vetementTypes' => StockItemVetementType::cases(),
            'marques'       => $this->itemRepository->findDistinctMarques(),
            'taillesEquip'  => array_values(array_unique(array_merge(
                $this->itemRepository->findDistinctTaillesByKind(StockItemKind::EQUIPEMENT),
                self::TAILLES_EQUIPEMENT,
            ))),
            'contenances'   => array_values(array_unique(array_merge(
                $this->itemRepository->findDistinctTaillesByKind(StockItemKind::EPICERIE),
                self::CONTENANCES_EPICERIE,
            ))),
            'couleurs'      => $this->itemRepository->findDistinctCouleurs(),
        ];
    }

    private function applyManualFields(StockItem $item, Request $request): void
    {
        $kind = StockItemKind::tryFrom($request->request->get('kind', ''));
        $item->setKind($kind);
        $item->setMarque(trim($request->request->get('marque', '')) ?: null);
        $item->setTaille(trim($request->request->get('taille', '')) ?: null);

        if ($kind === StockItemKind::EQUIPEMENT) {
            $item->setCouleur(trim($request->request->get('couleur', '')) ?: null);
            $item->setTypeVetement(StockItemVetementType::tryFrom($request->request->get('typeVetement', '')));
        } else {
            $item->setCouleur(null);
            $item->setTypeVetement(null);
        }
    }

    #[Route('/items/{id}/mouvement', name: 'items_movement', methods: ['POST'])]
    public function itemMovement(StockItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('stock_movement_' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dashboard');
        }

        $action   = $request->request->get('action');
        $quantite = (int) $request->request->get('quantite', 0);
        $note     = trim((string) $request->request->get('note', '')) ?: null;
        $licencieUuid = $request->request->get('licencie_uuid') ?: null;

        $typeMap = [
            'entree'   => [StockMovementType::ENTREE, StockMovementSource::MANUEL],
            'sortie'   => [StockMovementType::SORTIE, StockMovementSource::MANUEL],
            'dotation' => [StockMovementType::SORTIE, StockMovementSource::DOTATION],
            'rebut'    => [StockMovementType::REBUT,  StockMovementSource::MANUEL],
        ];

        if (!isset($typeMap[$action])) {
            $this->addFlash('error', 'Action invalide.');
            return $this->redirectToRoute('admin_stock_dashboard');
        }

        [$type, $source] = $typeMap[$action];

        $licencie = null;
        if ($source === StockMovementSource::DOTATION) {
            if ($licencieUuid === null) {
                $this->addFlash('error', 'Veuillez sélectionner un licencié pour une dotation.');
                return $this->redirectToRoute('admin_stock_dashboard');
            }

            $licencie = $this->licencieRepository->findOneBy(['uuid' => $licencieUuid]);

            if ($licencie === null) {
                $this->addFlash('error', 'Licencié introuvable.');
                return $this->redirectToRoute('admin_stock_dashboard');
            }

            $dossier = $licencie->getDossierClub();
            if ($dossier === null || $dossier->getStatus() !== LicenceStatus::VALIDATED) {
                $this->addFlash('error', sprintf(
                    'La dotation ne peut être enregistrée qu\'après confirmation du paiement de %s.',
                    $licencie->getNomPrenom(),
                ));
                return $this->redirectToRoute('admin_stock_dashboard');
            }
        }

        try {
            $movement = $this->stockService->recordMovement(
                $item,
                $quantite,
                $type,
                $source,
                $this->getUser(),
                $note,
            );

            if ($licencie !== null) {
                $movement->setLicencie($licencie);
                $this->em->flush();
            }

            $this->addFlash('success', sprintf(
                '%s de %d "%s" enregistrée%s.',
                $type->label(),
                $quantite,
                $item->getNom(),
                $licencie !== null ? ' → ' . $licencie->getNomPrenom() : '',
            ));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_dashboard');
    }

    #[Route('/items/{id}/supprimer', name: 'items_delete', methods: ['POST'])]
    public function itemDelete(StockItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_stock_item_' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dashboard');
        }

        $nom = $item->getNom();
        $this->em->remove($item);
        $this->em->flush();
        $this->addFlash('success', sprintf('Article "%s" supprimé.', $nom));

        return $this->redirectToRoute('admin_stock_dashboard');
    }

    #[Route('/mouvements', name: 'mouvements_list', methods: ['GET'])]
    public function mouvementsList(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $filters = array_filter([
            'item_id'   => $request->query->get('item_id'),
            'type'      => $request->query->get('type'),
            'source'    => $request->query->get('source'),
            'date_from' => $request->query->get('date_from'),
            'date_to'   => $request->query->get('date_to'),
        ]);

        ['movements' => $movements, 'total' => $total] = $this->movementRepository
            ->findWithFilters($season, $filters, $page, self::PER_PAGE);

        return $this->render('admin/stock/mouvements/list.html.twig', [
            'movements'  => $movements,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => $filters,
            'items'      => $this->itemRepository->findBySeason($season),
            'types'      => StockMovementType::cases(),
            'sources'    => StockMovementSource::cases(),
            'season'     => $season,
        ]);
    }

    #[Route('/categories', name: 'categories_list', methods: ['GET'])]
    public function categoriesList(): Response
    {
        $newCategoryForm = $this->createForm(StockCategoryType::class, new StockCategory(), [
            'action' => $this->generateUrl('admin_stock_categories_new'),
        ]);

        return $this->render('admin/stock/categories/list.html.twig', [
            'categories'      => $this->categoryRepository->findAllOrderedByPosition(),
            'newCategoryForm' => $newCategoryForm,
        ]);
    }

    #[Route('/categories/nouveau', name: 'categories_new', methods: ['POST'])]
    public function categoryNew(Request $request): Response
    {
        $category = new StockCategory();
        $form     = $this->createForm(StockCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();
            $this->addFlash('success', sprintf('Catégorie "%s" créée.', $category->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_stock_categories_list');
    }

    #[Route('/categories/{id}/modifier', name: 'categories_edit', methods: ['POST'])]
    public function categoryEdit(StockCategory $category, Request $request): Response
    {
        $form = $this->createForm(StockCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', sprintf('Catégorie "%s" mise à jour.', $category->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_stock_categories_list');
    }

    #[Route('/categories/{id}/supprimer', name: 'categories_delete', methods: ['POST'])]
    public function categoryDelete(StockCategory $category, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_category_' . $category->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_categories_list');
        }

        $name = $category->getName();
        $this->em->remove($category);
        $this->em->flush();
        $this->addFlash('success', sprintf('Catégorie "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_stock_categories_list');
    }

}

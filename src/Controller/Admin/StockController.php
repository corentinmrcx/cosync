<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\ManualMovementData;
use App\Entity\Fournisseur;
use App\Entity\StockCategory;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Form\StockCategoryType;
use App\Form\StockItemType;
use App\Repository\CommandeRepository;
use App\Repository\FournisseurRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockCategoryRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\Pdf\InventairePdfService;
use App\Service\SeasonContext;
use App\Service\Stock\AchatService;
use App\Service\Stock\StockService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly AchatService $achatService,
        private readonly CommandeRepository $commandeRepository,
        private readonly SeasonContext $seasonContext,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $season             = $this->seasonContext->getCurrentSeason();
        $aCommanderCount    = 0;
        $commandesEnAttente = 0;

        if ($season !== null) {
            foreach ($this->achatService->computeACommander($season) as $groupe) {
                foreach ($groupe['lignes'] as $ligne) {
                    $aCommanderCount += $ligne['aCommander'];
                }
            }
            foreach ($this->commandeRepository->findBySeason($season) as $commande) {
                if ($commande->getStatut()->isEnAttente()) {
                    $commandesEnAttente++;
                }
            }
        }

        return $this->render('admin/stock/dashboard.html.twig', [
            'data'               => $this->stockService->getDashboardData(),
            'season'             => $season,
            'aCommanderCount'    => $aCommanderCount,
            'commandesEnAttente' => $commandesEnAttente,
        ]);
    }

    #[Route('/gestion', name: 'gestion', methods: ['GET'])]
    public function gestion(Request $request): Response
    {
        $season          = $this->seasonContext->getCurrentSeason();
        $showArchived    = $request->query->getBoolean('archivés', false);

        return $this->render('admin/stock/gestion.html.twig', [
            'summary'          => $this->stockService->getStockSummary($showArchived),
            'showArchived'     => $showArchived,
            'season'           => $season,
            'licenciesValides' => $season !== null ? $this->licencieRepository->findValidatedBySeason($season) : [],
            'taillesConnues'   => self::TAILLES_EQUIPEMENT,
            'types'            => StockMovementType::cases(),
            'sources'          => StockMovementSource::cases(),
        ]);
    }

    #[Route('/inventaire.pdf', name: 'inventaire_pdf', methods: ['GET'])]
    public function inventairePdf(InventairePdfService $pdfService): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        $pdf    = $pdfService->generate($this->stockService->getInventaireData(), $season?->getLabel());

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="inventaire_%s.pdf"', (new \DateTimeImmutable())->format('Y-m-d')),
        ]);
    }

    #[Route('/items/nouveau', name: 'items_new', methods: ['GET', 'POST'])]
    public function itemNew(Request $request): Response
    {
        $item = new StockItem();
        $form = $this->createForm(StockItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyEditableFields($item, $request);
            $this->em->persist($item);
            $this->em->flush();
            $this->addFlash('success', sprintf('Article "%s" créé.', $item->getNom()));
            return $this->redirectToRoute('admin_stock_gestion');
        }

        return $this->render('admin/stock/items/form.html.twig', ['form' => $form] + $this->itemFormContext(null));
    }

    #[Route('/items/{id}/modifier', name: 'items_edit', methods: ['GET', 'POST'])]
    public function itemEdit(StockItem $item, Request $request): Response
    {
        $form = $this->createForm(StockItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyEditableFields($item, $request);
            $this->em->flush();
            $this->addFlash('success', sprintf('Article "%s" mis à jour.', $item->getNom()));
            return $this->redirectToRoute('admin_stock_gestion');
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

    /** Lit les champs conditionnels du formulaire article et délègue l'application au service. */
    private function applyEditableFields(StockItem $item, Request $request): void
    {
        $this->stockService->applyEditableFields(
            $item,
            StockItemKind::tryFrom((string) $request->request->get('kind', '')),
            trim((string) $request->request->get('marque', '')) ?: null,
            trim((string) $request->request->get('couleur', '')) ?: null,
            trim((string) $request->request->get('taille', '')) ?: null,
            StockItemVetementType::tryFrom((string) $request->request->get('typeVetement', '')),
        );
    }

    #[Route('/items/{id}/mouvement', name: 'items_movement', methods: ['POST'])]
    public function itemMovement(StockItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('stock_movement_' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_gestion');
        }

        $data = new ManualMovementData(
            action: (string) $request->request->get('action', ''),
            quantite: (int) $request->request->get('quantite', 0),
            taille: trim((string) $request->request->get('taille', '')) ?: null,
            note: trim((string) $request->request->get('note', '')) ?: null,
            licencieUuid: $request->request->get('licencie_uuid') ?: null,
        );

        try {
            $movement = $this->stockService->recordManualMovement($item, $data, $this->getUser());
            $this->addFlash('success', sprintf(
                '%s de %d "%s" enregistrée%s.',
                $movement->getType()->label(),
                $movement->getQuantite(),
                $item->getNom(),
                $movement->getLicencie() !== null ? ' → ' . $movement->getLicencie()->getNomPrenom() : '',
            ));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_gestion');
    }

    #[Route('/mouvements/{id}/supprimer', name: 'mouvements_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function mouvementDelete(StockMovement $movement, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_stock_movement_' . $movement->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_mouvements_list');
        }

        try {
            $this->stockService->deleteManualMovement($movement);
            $this->addFlash('success', 'Mouvement supprimé, stock recalculé.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_mouvements_list');
    }

    #[Route('/items/{id}/supprimer', name: 'items_delete', methods: ['POST'])]
    public function itemDelete(StockItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_stock_item_' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_gestion');
        }

        $nom = $item->getNom();

        if ($this->movementRepository->count(['item' => $item]) > 0) {
            $item->setActif(false);
            $this->em->flush();
            $this->addFlash('info', sprintf(
                '"%s" archivé — il disparaît des listes, mais l\'historique des mouvements est conservé.',
                $nom,
            ));
            return $this->redirectToRoute('admin_stock_gestion');
        }

        try {
            $this->em->remove($item);
            $this->em->flush();
            $this->addFlash('success', sprintf('Article "%s" supprimé.', $nom));
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', sprintf(
                'Impossible de supprimer "%s" : il est référencé par une dotation ou une commande.',
                $nom,
            ));
        }

        return $this->redirectToRoute('admin_stock_gestion');
    }

    #[Route('/items/{id}/restaurer', name: 'items_restore', methods: ['POST'])]
    public function itemRestore(StockItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('restore_stock_item_' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_gestion', ['archivés' => '1']);
        }

        $item->setActif(true);
        $this->em->flush();
        $this->addFlash('success', sprintf('Article "%s" restauré dans le catalogue actif.', $item->getNom()));

        return $this->redirectToRoute('admin_stock_gestion', ['archivés' => '1']);
    }

    #[Route('/mouvements', name: 'mouvements_list', methods: ['GET'])]
    public function mouvementsList(Request $request): Response
    {
        $page    = max(1, (int) $request->query->get('page', 1));
        $filters = array_filter([
            'item_id'   => $request->query->get('item_id'),
            'type'      => $request->query->get('type'),
            'source'    => $request->query->get('source'),
            'date_from' => $request->query->get('date_from'),
            'date_to'   => $request->query->get('date_to'),
        ]);

        ['movements' => $movements, 'total' => $total] = $this->movementRepository
            ->findWithFilters($filters, $page, self::PER_PAGE);

        return $this->render('admin/stock/mouvements/list.html.twig', [
            'movements'  => $movements,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => $filters,
            'items'      => $this->itemRepository->findAllOrdered(),
            'types'      => StockMovementType::cases(),
            'sources'    => StockMovementSource::cases(),
            'season'     => $this->seasonContext->getCurrentSeason(),
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

    #[Route('/fournisseurs', name: 'fournisseurs_list', methods: ['GET'])]
    public function fournisseursList(): Response
    {
        return $this->render('admin/stock/fournisseurs/list.html.twig', [
            'fournisseurs' => $this->fournisseurRepository->findAllOrdered(),
        ]);
    }

    #[Route('/fournisseurs/nouveau', name: 'fournisseurs_new', methods: ['POST'])]
    public function fournisseurNew(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fournisseur_new', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_fournisseurs_list');
        }

        $nom = trim((string) $request->request->get('nom', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Le nom du fournisseur est obligatoire.');
            return $this->redirectToRoute('admin_stock_fournisseurs_list');
        }

        $fournisseur = (new Fournisseur())
            ->setNom($nom)
            ->setContact(trim((string) $request->request->get('contact', '')) ?: null)
            ->setEmail(trim((string) $request->request->get('email', '')) ?: null);
        $this->em->persist($fournisseur);
        $this->em->flush();
        $this->addFlash('success', sprintf('Fournisseur "%s" créé.', $nom));

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }

    #[Route('/fournisseurs/{id}/modifier', name: 'fournisseurs_edit', methods: ['POST'])]
    public function fournisseurEdit(Fournisseur $fournisseur, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fournisseur_edit_' . $fournisseur->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_fournisseurs_list');
        }

        $nom = trim((string) $request->request->get('nom', ''));
        if ($nom !== '') {
            $fournisseur->setNom($nom);
        }
        $fournisseur
            ->setContact(trim((string) $request->request->get('contact', '')) ?: null)
            ->setEmail(trim((string) $request->request->get('email', '')) ?: null)
            ->setActif($request->request->get('actif') === '1');
        $this->em->flush();
        $this->addFlash('success', 'Fournisseur mis à jour.');

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }

    #[Route('/fournisseurs/{id}/supprimer', name: 'fournisseurs_delete', methods: ['POST'])]
    public function fournisseurDelete(Fournisseur $fournisseur, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fournisseur_delete_' . $fournisseur->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_fournisseurs_list');
        }

        $nom = $fournisseur->getNom();
        $this->em->remove($fournisseur);
        $this->em->flush();
        $this->addFlash('success', sprintf('Fournisseur "%s" supprimé.', $nom));

        return $this->redirectToRoute('admin_stock_fournisseurs_list');
    }

}

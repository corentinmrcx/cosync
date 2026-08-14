<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\ManualMovementData;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockActionManuelle;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Form\StockItemType;
use App\Repository\LicencieRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Security\CsrfGuard;
use App\Service\Pdf\InventairePdfService;
use App\Service\Saison\SeasonContext;
use App\Service\Stock\StockItemFormContext;
use App\Service\Stock\StockItemService;
use App\Service\Stock\StockMovementService;
use App\Service\Stock\StockReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le stock physique appartient au club, pas à une saison : ni StockItem ni StockMovement ne
 * portent de season_id. Aucune action ici n'exige donc de saison courante — seule la remise
 * d'une dotation a besoin de la liste des licenciés, qui est, elle, saisonnière.
 */
#[Route('/admin/stock', name: 'admin_stock_')]
class StockController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly StockMovementService $mouvements,
        private readonly StockReportService $rapports,
        private readonly StockItemService $itemService,
        private readonly StockItemFormContext $itemFormContext,
        private readonly InventairePdfService $pdfService,
        private readonly StockItemRepository $itemRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly SeasonContext $seasonContext,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/stock/dashboard.html.twig', [
            'data' => $this->rapports->getDashboardData(),
        ]);
    }

    #[Route('/gestion', name: 'gestion', methods: ['GET'])]
    public function gestion(Request $request): Response
    {
        $showArchived = $request->query->getBoolean('archivés', false);
        // Saison facultative : sans elle le stock reste consultable, seule la remise
        // d'une dotation est privée de destinataires.
        $season = $this->seasonContext->getCurrentSeason();

        return $this->render('admin/stock/gestion.html.twig', [
            'summary' => $this->rapports->getStockSummary($showArchived),
            'showArchived' => $showArchived,
            'licenciesValides' => $season !== null ? $this->licencieRepository->findValidatedBySeason($season) : [],
            'types' => StockMovementType::cases(),
            'sources' => StockMovementSource::cases(),
        ]);
    }

    #[Route('/inventaire.pdf', name: 'inventaire_pdf', methods: ['GET'])]
    public function inventairePdf(): Response
    {
        // Pas de label de saison : l'inventaire est un état du club à une date, pas d'une saison.
        $pdf = $this->pdfService->generate($this->rapports->getInventaireData(), null);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
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
            $this->itemService->creer($item);
            $this->addFlash('success', sprintf('Article "%s" créé.', $item->getNom()));

            return $this->redirectToRoute('admin_stock_gestion');
        }

        return $this->render('admin/stock/items/form.html.twig', ['form' => $form] + $this->itemFormContext->build(null));
    }

    #[Route('/items/{id}/modifier', name: 'items_edit', methods: ['GET', 'POST'])]
    public function itemEdit(StockItem $item, Request $request): Response
    {
        $form = $this->createForm(StockItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyEditableFields($item, $request);
            $this->itemService->enregistrer($item);
            $this->addFlash('success', sprintf('Article "%s" mis à jour.', $item->getNom()));

            return $this->redirectToRoute('admin_stock_gestion');
        }

        return $this->render('admin/stock/items/form.html.twig', ['form' => $form] + $this->itemFormContext->build($item));
    }

    /** Lit les champs conditionnels du formulaire article et délègue l'application au service. */
    private function applyEditableFields(StockItem $item, Request $request): void
    {
        $this->itemService->applyEditableFields(
            $item,
            StockItemKind::tryFrom((string) $request->request->get('kind', '')),
            trim((string) $request->request->get('marque', '')) ?: null,
            trim((string) $request->request->get('couleur', '')) ?: null,
            trim((string) $request->request->get('taille', '')) ?: null,
            StockItemVetementType::tryFrom((string) $request->request->get('typeVetement', '')),
        );
    }

    #[Route('/items/{id}/mouvement', name: 'items_movement', methods: ['POST'])]
    public function itemMovement(StockItem $item, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf->valider('stock_movement_' . $item->getId(), $request);

        $action = StockActionManuelle::tryFrom((string) $request->request->get('action', ''));

        if ($action === null) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('admin_stock_gestion');
        }

        $data = new ManualMovementData(
            action: $action,
            quantite: (int) $request->request->get('quantite', 0),
            taille: trim((string) $request->request->get('taille', '')) ?: null,
            note: trim((string) $request->request->get('note', '')) ?: null,
            licencieUuid: $request->request->get('licencie_uuid') ?: null,
        );

        try {
            $movement = $this->mouvements->recordManualMovement($item, $data, $user);
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
        $this->csrf->valider('delete_stock_movement_' . $movement->getId(), $request);

        try {
            $this->mouvements->deleteManualMovement($movement);
            $this->addFlash('success', 'Mouvement supprimé, stock recalculé.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_mouvements_list');
    }

    #[Route('/items/{id}/supprimer', name: 'items_delete', methods: ['POST'])]
    public function itemDelete(StockItem $item, Request $request): Response
    {
        $this->csrf->valider('delete_stock_item_' . $item->getId(), $request);

        $nom = $item->getNom();

        try {
            $archive = $this->itemService->supprimerOuArchiver($item);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_gestion');
        }

        $this->addFlash(
            $archive ? 'info' : 'success',
            $archive
                ? sprintf('"%s" archivé — il disparaît des listes, mais l\'historique des mouvements est conservé.', $nom)
                : sprintf('Article "%s" supprimé.', $nom),
        );

        return $this->redirectToRoute('admin_stock_gestion');
    }

    #[Route('/items/{id}/restaurer', name: 'items_restore', methods: ['POST'])]
    public function itemRestore(StockItem $item, Request $request): Response
    {
        $this->csrf->valider('restore_stock_item_' . $item->getId(), $request);

        $this->itemService->restaurer($item);
        $this->addFlash('success', sprintf('Article "%s" restauré dans le catalogue actif.', $item->getNom()));

        return $this->redirectToRoute('admin_stock_gestion', ['archivés' => '1']);
    }

    #[Route('/mouvements', name: 'mouvements_list', methods: ['GET'])]
    public function mouvementsList(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $filters = array_filter([
            'search' => trim((string) $request->query->get('search', '')),
            'item_id' => $request->query->get('item_id'),
            'type' => $request->query->get('type'),
            'source' => $request->query->get('source'),
            'date_from' => $request->query->get('date_from'),
            'date_to' => $request->query->get('date_to'),
        ]);

        ['movements' => $movements, 'total' => $total] = $this->movementRepository
            ->findWithFilters($filters, $page, self::PER_PAGE);

        return $this->render('admin/stock/mouvements/list.html.twig', [
            'movements' => $movements,
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'filters' => $filters,
            'items' => $this->itemRepository->findAllOrdered(),
            'types' => StockMovementType::cases(),
            'sources' => StockMovementSource::cases(),
        ]);
    }
}

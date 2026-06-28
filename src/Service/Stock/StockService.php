<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\StockCategoryRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StockService
{
    public function __construct(
        private readonly StockMovementRepository $movementRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly StockCategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrentStock(StockItem $item): int
    {
        return $this->movementRepository->getCurrentStock($item);
    }

    public function recordMovement(
        StockItem $item,
        int $quantite,
        StockMovementType $type,
        StockMovementSource $source,
        ?User $createdBy,
        ?string $note,
        ?string $sumupTransactionId = null,
    ): StockMovement {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }
        if ($type === StockMovementType::REBUT && empty(trim($note ?? ''))) {
            throw new \InvalidArgumentException('Une justification est obligatoire pour un rebut.');
        }

        $movement = new StockMovement();
        $movement->setItem($item);
        $movement->setQuantite($quantite);
        $movement->setType($type);
        $movement->setSource($source);
        $movement->setNote($note ?: null);
        $movement->setCreatedBy($createdBy);
        $movement->setSumupTransactionId($sumupTransactionId);

        $this->em->persist($movement);
        $this->em->flush();

        return $movement;
    }

    /**
     * @return array<int, array{category: \App\Entity\StockCategory|null, items: array<int, array{item: StockItem, stock: int, status: string}>}>
     */
    public function getStockSummary(Season $season): array
    {
        $items      = $this->itemRepository->findBySeason($season);
        $categories = $this->categoryRepository->findAllOrderedByPosition();

        $byCategory = [];
        foreach ($items as $item) {
            $catId = $item->getCategory()?->getId() ?? 0;
            $byCategory[$catId][] = $item;
        }

        $summary = [];

        foreach ($categories as $category) {
            $catItems = $byCategory[$category->getId()] ?? [];
            if (empty($catItems)) {
                continue;
            }
            $summary[] = [
                'category' => $category,
                'items'    => array_map($this->buildItemRow(...), $catItems),
            ];
        }

        if (!empty($byCategory[0])) {
            $summary[] = [
                'category' => null,
                'items'    => array_map($this->buildItemRow(...), $byCategory[0]),
            ];
        }

        return $summary;
    }

    /**
     * Données de synthèse pour le tableau de bord stock : compteurs + articles en alerte.
     *
     * @return array{
     *   nbArticles: int,
     *   nbAlertes: int,
     *   nbRuptures: int,
     *   valeurStock: float,
     *   alertes: array<int, array{item: StockItem, stock: int, status: string}>
     * }
     */
    public function getDashboardData(Season $season): array
    {
        $items = $this->itemRepository->findBySeason($season);

        $alertes     = [];
        $nbRuptures  = 0;
        $valeurStock = 0.0;

        foreach ($items as $item) {
            $stock = $this->movementRepository->getCurrentStock($item);

            if ($item->getPrixAchat() !== null && $stock > 0) {
                $valeurStock += $stock * $item->getPrixAchat();
            }

            $seuil = $item->getAlertSeuil();
            if ($seuil === null) {
                continue;
            }

            if ($stock <= 0) {
                $alertes[] = ['item' => $item, 'stock' => $stock, 'status' => 'danger'];
                $nbRuptures++;
            } elseif ($stock <= $seuil) {
                $alertes[] = ['item' => $item, 'stock' => $stock, 'status' => 'warning'];
            }
        }

        // Ruptures (danger) en tête, puis stock bas
        usort(
            $alertes,
            static fn (array $a, array $b): int => ($a['status'] === 'danger' ? 0 : 1) <=> ($b['status'] === 'danger' ? 0 : 1),
        );

        return [
            'nbArticles'  => count($items),
            'nbAlertes'   => count($alertes),
            'nbRuptures'  => $nbRuptures,
            'valeurStock' => $valeurStock,
            'alertes'     => $alertes,
        ];
    }

    /** @return array{item: StockItem, stock: int, status: string} */
    private function buildItemRow(StockItem $item): array
    {
        $stock  = $this->movementRepository->getCurrentStock($item);
        $seuil  = $item->getAlertSeuil();
        $status = 'ok';

        if ($seuil !== null) {
            if ($stock <= 0) {
                $status = 'danger';
            } elseif ($stock <= $seuil) {
                $status = 'warning';
            }
        }

        return ['item' => $item, 'stock' => $stock, 'status' => $status];
    }
}

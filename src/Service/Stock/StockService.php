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

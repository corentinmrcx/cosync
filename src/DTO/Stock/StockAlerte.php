<?php declare(strict_types=1);

namespace App\DTO\Stock;

use App\Entity\StockItem;
use App\Enum\StockAlerteNiveau;

/** Un article sous son seuil d'alerte. */
final class StockAlerte
{
    public function __construct(
        public readonly StockItem $item,
        public readonly int $stock,
        public readonly StockAlerteNiveau $niveau,
    ) {}
}

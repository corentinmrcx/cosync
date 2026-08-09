<?php declare(strict_types=1);

namespace App\DTO\Stock;

use App\Entity\StockItem;
use App\Enum\StockAlerteNiveau;

/** Une ligne de la feuille d'inventaire : l'article et sa ventilation par taille. */
final class StockInventaireLigne
{
    /** @param list<StockTailleLigne> $tailles */
    public function __construct(
        public readonly StockItem $item,
        public readonly int $total,
        public readonly StockAlerteNiveau $niveau,
        public readonly array $tailles,
    ) {}
}

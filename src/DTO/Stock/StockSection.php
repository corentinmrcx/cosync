<?php declare(strict_types=1);

namespace App\DTO\Stock;

use App\Entity\StockCategory;

/**
 * Les articles d'une catégorie. `category` est null pour ceux qui n'en ont pas :
 * ils ferment la liste.
 *
 * @template T of StockLigne|StockInventaireLigne
 */
final class StockSection
{
    /** @param list<T> $items */
    public function __construct(
        public readonly ?StockCategory $category,
        public readonly array $items,
    ) {}
}

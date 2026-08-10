<?php declare(strict_types=1);

namespace App\DTO\Stock;

/** Compteurs et alertes du tableau de bord stock. */
final class StockTableauDeBord
{
    /** @param list<StockAlerte> $alertes ruptures en tête, puis stock bas */
    public function __construct(
        public readonly int $nbArticles,
        public readonly int $nbAlertes,
        public readonly int $nbRuptures,
        public readonly float $valeurStock,
        public readonly array $alertes,
    ) {}
}

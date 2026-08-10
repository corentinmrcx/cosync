<?php declare(strict_types=1);

namespace App\DTO\Stock;

/** Stock d'un article pour une taille donnée. « — » désigne les articles sans déclinaison. */
final class StockTailleLigne
{
    public const SANS_TAILLE = '—';

    public function __construct(
        public readonly string $taille,
        public readonly int $stock,
    ) {}

    public function sansTaille(): bool
    {
        return $this->taille === self::SANS_TAILLE;
    }
}

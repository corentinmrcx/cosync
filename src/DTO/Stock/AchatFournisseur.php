<?php declare(strict_types=1);

namespace App\DTO\Stock;

/**
 * Ce que le club achète chez un fournisseur : un devis viendra se mettre en face de ce bloc.
 */
final class AchatFournisseur
{
    /** @param list<AchatArticle> $articles par désignation */
    public function __construct(
        public readonly string $nom,
        public readonly array $articles,
        public readonly int $total,
    ) {}
}

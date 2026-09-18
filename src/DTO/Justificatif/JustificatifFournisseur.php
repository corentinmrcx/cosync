<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

/**
 * Les articles d'un fournisseur : un devis viendra se mettre en face de ce bloc.
 */
final class JustificatifFournisseur
{
    /** @param list<JustificatifArticle> $articles ceux à commander comme ceux déjà couverts */
    public function __construct(
        public readonly string $nom,
        public readonly array $articles,
        public readonly int $total,
    ) {}
}

<?php declare(strict_types=1);

namespace App\DTO\Stock;

/**
 * Ce qu'il advient d'un article qu'on cherche à retirer du catalogue : suppression réelle
 * ou archivage, et la raison. L'écran de confirmation l'annonce avant d'agir — supprimer
 * un article emporte ses mouvements, on ne le découvre pas après coup.
 */
final class SuppressionArticle
{
    private function __construct(
        public readonly bool $supprimable,
        /** Mouvements qui partiraient avec l'article. */
        public readonly int $mouvementsEmportes,
        /** Pourquoi l'article ne peut qu'être archivé — null s'il est supprimable. */
        public readonly ?string $motifArchivage,
    ) {}

    public static function supprimable(int $mouvementsEmportes): self
    {
        return new self(true, $mouvementsEmportes, null);
    }

    public static function aArchiver(string $motif): self
    {
        return new self(false, 0, $motif);
    }
}

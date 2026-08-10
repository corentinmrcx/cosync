<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\StockActionManuelle;

/**
 * Mouvement de stock saisi à la main depuis la modale de gestion.
 */
final class ManualMovementData
{
    public function __construct(
        public readonly StockActionManuelle $action,
        public readonly int $quantite,
        public readonly ?string $taille,
        public readonly ?string $note,
        /** Requis pour une dotation : c'est la personne qui reçoit l'article. */
        public readonly ?string $licencieUuid,
    ) {}
}

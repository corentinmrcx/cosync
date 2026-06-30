<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Données d'un mouvement de stock saisi à la main depuis la modale de gestion.
 * Transformé depuis la Request par le contrôleur, consommé par StockService::recordManualMovement().
 */
final class ManualMovementData
{
    public function __construct(
        public readonly string $action,        // entree | sortie | dotation | rebut
        public readonly int $quantite,
        public readonly ?string $taille,
        public readonly ?string $note,
        public readonly ?string $licencieUuid, // requis pour une dotation
    ) {}
}

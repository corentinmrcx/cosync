<?php declare(strict_types=1);

namespace App\Enum;

enum StockMovementSource: string
{
    case MANUEL = 'manuel';
    case DOTATION = 'dotation';
    case COMMANDE = 'commande';
    case SUMUP = 'sumup'; // Phase 2 — intégration SumUp API v2.1

    public function label(): string
    {
        return match ($this) {
            self::MANUEL => 'Manuel',
            self::DOTATION => 'Dotation joueur',
            self::COMMANDE => 'Réception commande',
            self::SUMUP => 'SumUp',
        };
    }
}

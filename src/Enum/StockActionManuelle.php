<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce que l'admin choisit dans la modale de mouvement de stock.
 *
 * Distinct de StockMovementType : « dotation » et « sortie » produisent toutes deux une
 * sortie, mais ne portent pas la même source et n'obéissent pas à la même garde de stock.
 */
enum StockActionManuelle: string
{
    case ENTREE = 'entree';
    case SORTIE = 'sortie';
    case DOTATION = 'dotation';
    case REBUT = 'rebut';

    public function type(): StockMovementType
    {
        return match ($this) {
            self::ENTREE => StockMovementType::ENTREE,
            self::SORTIE, self::DOTATION => StockMovementType::SORTIE,
            self::REBUT => StockMovementType::REBUT,
        };
    }

    public function source(): StockMovementSource
    {
        return $this === self::DOTATION ? StockMovementSource::DOTATION : StockMovementSource::MANUEL;
    }

    /**
     * La dotation reste autorisée à découvert : l'équipement est souvent fabriqué à la
     * commande, un stock à zéro au moment de la remise est donc légitime.
     */
    public function interditLeDecouvert(): bool
    {
        return $this === self::SORTIE || $this === self::REBUT;
    }

    public function exigeUnLicencie(): bool
    {
        return $this === self::DOTATION;
    }
}

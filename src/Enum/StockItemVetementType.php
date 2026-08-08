<?php declare(strict_types=1);

namespace App\Enum;

enum StockItemVetementType: string
{
    case HAUT = 'haut';
    case BAS = 'bas';
    case CHAUSSURES = 'chaussures';

    public function label(): string
    {
        return match ($this) {
            self::HAUT => 'Haut',
            self::BAS => 'Bas',
            self::CHAUSSURES => 'Pieds',
        };
    }

    /** Champ correspondant dans DossierClub */
    public function dossierField(): string
    {
        return match ($this) {
            self::HAUT => 'tailleHaut',
            self::BAS => 'tailleBas',
            self::CHAUSSURES => 'pointure',
        };
    }
}

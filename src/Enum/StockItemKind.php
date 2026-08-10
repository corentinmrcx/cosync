<?php declare(strict_types=1);

namespace App\Enum;

enum StockItemKind: string
{
    case EQUIPEMENT = 'equipement';
    case EPICERIE = 'epicerie';

    public function label(): string
    {
        return match ($this) {
            self::EQUIPEMENT => 'Équipement sportif',
            self::EPICERIE => 'Épicerie / Boisson',
        };
    }
}

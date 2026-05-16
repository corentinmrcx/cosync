<?php declare(strict_types=1);

namespace App\Enum;

enum StockMovementType: string
{
    case ENTREE = 'entree';
    case SORTIE = 'sortie';
    case REBUT = 'rebut';

    public function label(): string
    {
        return match($this) {
            self::ENTREE => 'Entrée',
            self::SORTIE => 'Sortie',
            self::REBUT => 'Rebut',
        };
    }
}

<?php declare(strict_types=1);

namespace App\Enum;

use App\Service\Referentiel\Tailles;

/**
 * Liste de tailles qu'un article de stock peut porter : un maillot se décline en S/M/L,
 * une paire de chaussettes en pointures, une bouteille en rien du tout.
 */
enum StockTailleProfil: string
{
    case VETEMENT = 'vetement';
    case POINTURE = 'pointure';
    case AUCUNE = 'aucune';

    /** @return list<string> */
    public function options(): array
    {
        return match ($this) {
            self::VETEMENT => Tailles::toutes(),
            self::POINTURE => Tailles::pointures(),
            self::AUCUNE => [],
        };
    }
}

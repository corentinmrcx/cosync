<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Échelle de tailles qu'un article de stock peut porter : un maillot se décline en S/M/L,
 * une paire de chaussettes en pointures, une bouteille en rien du tout.
 *
 * Le profil dit *quelle* échelle s'applique ; les valeurs, elles, viennent du référentiel
 * réglé en admin (TailleReferentiel).
 */
enum StockTailleProfil: string
{
    case VETEMENT = 'vetement';
    case POINTURE = 'pointure';
    case AUCUNE = 'aucune';

    public function type(): ?TailleType
    {
        return match ($this) {
            self::VETEMENT => TailleType::VETEMENT,
            self::POINTURE => TailleType::POINTURE,
            self::AUCUNE => null,
        };
    }
}

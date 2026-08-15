<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce que mesure une taille. Un haut et un bas partagent la même échelle ; les pieds ont
 * la leur — proposer « XL » pour une paire de chaussettes n'a aucun sens.
 */
enum TailleType: string
{
    case VETEMENT = 'vetement';
    case POINTURE = 'pointure';

    public function label(): string
    {
        return match ($this) {
            self::VETEMENT => 'Vêtement',
            self::POINTURE => 'Pointure',
        };
    }
}

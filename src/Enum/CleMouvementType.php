<?php declare(strict_types=1);

namespace App\Enum;

enum CleMouvementType: string
{
    case REMISE = 'remise';
    case RESTITUTION = 'restitution';
    case PERTE = 'perte';

    public function label(): string
    {
        return match($this) {
            self::REMISE => 'Remise',
            self::RESTITUTION => 'Restitution',
            self::PERTE => 'Perte',
        };
    }

    /** Effet du mouvement sur le nombre de clés détenues par la personne */
    public function impact(): int
    {
        return $this === self::REMISE ? 1 : -1;
    }
}

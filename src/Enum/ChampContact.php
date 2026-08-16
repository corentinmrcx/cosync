<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les deux coordonnées qu'un admin peut corriger à la main, et dont la correction
 * fait ensuite autorité sur l'import FootClubs jusqu'à ce qu'il la relâche.
 */
enum ChampContact: string
{
    case EMAIL = 'email';
    case TELEPHONE = 'telephone';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'L\'adresse mail',
            self::TELEPHONE => 'Le numéro de téléphone',
        };
    }
}

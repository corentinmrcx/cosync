<?php declare(strict_types=1);

namespace App\Enum;

enum CommandeStatut: string
{
    case BROUILLON = 'brouillon';
    case COMMANDEE = 'commandee';
    case RECUE_PARTIELLE = 'recue_partielle';
    case RECUE = 'recue';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::COMMANDEE => 'Commandée',
            self::RECUE_PARTIELLE => 'Reçue partiellement',
            self::RECUE => 'Reçue',
        };
    }

    /** Compte comme « stock à venir » (déduit du « à commander »). */
    public function isEnAttente(): bool
    {
        return $this === self::COMMANDEE || $this === self::RECUE_PARTIELLE;
    }
}

<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Civilité d'une personne nommée sur un document du club.
 *
 * Elle ne sert pas qu'à préfixer un nom : c'est elle qui accorde les participes des
 * formules d'attestation (« Je soussigné » / « Je soussignée »). Une attestation mal
 * accordée se remarque immédiatement et fait douter du sérieux du document.
 */
enum Civilite: string
{
    case M = 'm';
    case MME = 'mme';

    public function label(): string
    {
        return match ($this) {
            self::M => 'M.',
            self::MME => 'Mme',
        };
    }

    /** Marque du féminin à coller à un participe : « soussigné » + « e ». */
    public function marqueFeminin(): string
    {
        return $this === self::MME ? 'e' : '';
    }

    /** @return list<self> */
    public static function proposables(): array
    {
        return [self::M, self::MME];
    }
}

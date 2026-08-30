<?php declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient la ligne du planning — et donc **qui fait foi** sur son horaire.
 *
 * `FFF` : le district décide. Date, heure, catégorie et adversaire sont réécrits à chaque
 * synchronisation, sinon un report de match ne remonterait jamais dans le planning distribué.
 * `MANUEL` : le club décide, et rien ne vient l'écraser — c'est le cas des plateaux U7/U9,
 * absents du flux fédéral, et des matchs détachés à la main.
 */
enum MatchSource: string
{
    case MANUEL = 'manuel';
    case FFF = 'fff';

    public function label(): string
    {
        return match ($this) {
            self::MANUEL => 'Saisie du club',
            self::FFF => 'Calendrier FFF',
        };
    }

    /** Vrai si la synchronisation FFF a le droit de réécrire la ligne. */
    public function suitLaFff(): bool
    {
        return $this === self::FFF;
    }
}

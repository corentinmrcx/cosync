<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * Une même colonne porte successivement deux choses : le chemin absolu du PDF tant qu'il
 * n'est qu'en local, puis l'identifiant Drive une fois l'archivage réussi. Le slash initial
 * est ce qui les distingue.
 */
final class DrivePath
{
    public static function estLocal(?string $chemin): bool
    {
        return $chemin !== null && str_starts_with($chemin, '/');
    }

    public static function estArchive(?string $chemin): bool
    {
        return $chemin !== null && !self::estLocal($chemin);
    }
}

<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Cible CoSync d'une ligne d'import, déterminée par le layout à partir du fichier source.
 * CoSync ne connaît que deux cibles : licencié (joueur) et dirigeant (tout le reste).
 */
enum ImportRowType
{
    case LICENCIE;
    case DIRIGEANT;
    case SKIP;
}

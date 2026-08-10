<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Résultat d'une relance groupée : un dirigeant sans adresse email ne peut pas être relancé
 * par ce canal, il est compté à part pour que l'admin sache qu'il reste à joindre autrement.
 */
final class RelanceResultat
{
    public function __construct(
        public readonly int $envoyes,
        public readonly int $sansEmail,
    ) {}
}

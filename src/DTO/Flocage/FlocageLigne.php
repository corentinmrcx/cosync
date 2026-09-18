<?php declare(strict_types=1);

namespace App\DTO\Flocage;

/**
 * Une pièce à marquer, telle que le floqueur la prend en main : une taille, un texte.
 *
 * Ni nom ni équipe : le floqueur ne sait pas qui est Dupont et n'a pas à l'apprendre. Deux
 * pièces qui portent le même texte dans la même taille tiennent sur une ligne avec leur
 * quantité — c'est le même geste répété, pas deux consignes.
 */
final class FlocageLigne
{
    public function __construct(
        public readonly ?string $etiquetteTaille,
        public readonly string $texte,
        public readonly int $quantite,
    ) {}
}

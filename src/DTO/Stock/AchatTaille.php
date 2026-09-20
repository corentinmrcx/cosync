<?php declare(strict_types=1);

namespace App\DTO\Stock;

/**
 * Une déclinaison d'un article acheté : l'étiquette du carton (« 37-40 ») et la quantité,
 * c'est-à-dire les deux choses qu'on recopie chez le fournisseur.
 *
 * `etiquette` est nulle pour un article qui ne se décline pas — une paire de gants d'arbitre
 * n'a pas de taille, et lui en inventer une ferait chercher au catalogue ce qui n'y est pas.
 */
final class AchatTaille
{
    public function __construct(
        public readonly ?string $etiquette,
        public readonly int $quantite,
    ) {}
}

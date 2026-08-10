<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\DotationBesoin;

/**
 * Une équipe dans l'écran de suivi des dotations, avec ses besoins déjà ordonnés.
 */
final class DotationSuiviGroupe
{
    /** @param list<DotationBesoin> $besoins */
    public function __construct(
        public readonly string $nom,
        public readonly array $besoins,
        public readonly int $total,
        public readonly int $restants,
    ) {}
}

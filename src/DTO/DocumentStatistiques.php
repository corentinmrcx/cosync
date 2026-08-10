<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Avancement des signatures d'un document.
 *
 * `concernes` reste null pour les documents destinés aux dirigeants : le ciblage restreint
 * la population, et on n'affiche alors que le nombre de personnes encore attendues.
 */
final class DocumentStatistiques
{
    public function __construct(
        public readonly int $signes,
        public readonly ?int $concernes,
        public readonly int $enAttente,
    ) {}
}

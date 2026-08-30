<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Avancement des signatures d'un document.
 *
 * `concernes` reste null pour les documents destinés aux dirigeants : le ciblage restreint
 * la population, et on n'affiche alors que le nombre de personnes encore attendues.
 *
 * `relancables` est plus étroit que `enAttente` côté licenciés : une personne qui n'a pas
 * fini son inscription n'a pas signé, mais on ne la relance pas — son parcours lui
 * présentera le document avec le reste. Confondre les deux ferait vivre deux liens en
 * même temps sur la même personne.
 */
final class DocumentStatistiques
{
    public function __construct(
        public readonly int $signes,
        public readonly ?int $concernes,
        public readonly int $enAttente,
        public readonly int $relancables,
    ) {}
}

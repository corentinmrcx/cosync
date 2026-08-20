<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\StockItem;

/**
 * Une transition de fournisseur, lue dans le sens où l'admin la pense : l'article qu'on
 * commande désormais, et les anciens stocks à servir avant lui.
 *
 * En base, la relation est portée par l'article écoulé (`StockItem.remplaceArticle`) — c'est
 * là qu'elle doit vivre, une règle par carton et non une par kit. Mais déclarée dans ce
 * sens-là, elle se lit à l'envers de la décision qu'elle traduit : on ne se dit pas « ces
 * chaussettes Nike remplacent les ERIMA », on se dit « je commande de l'ERIMA maintenant, et
 * il me reste des Nike à sortir ». Ce DTO retourne la lecture, pas la donnée.
 */
final class EcoulementCorrespondance
{
    /** @param list<EcoulementSubstitut> $substituts */
    public function __construct(
        public readonly StockItem $principal,
        public readonly int $stockPrincipal,
        public readonly array $substituts,
    ) {}

    /** Total encore servable par les anciens stocks — ce que le club ne rachètera pas. */
    public function restantAEcouler(): int
    {
        return array_sum(array_map(
            static fn (EcoulementSubstitut $substitut): int => max(0, $substitut->restant),
            $this->substituts,
        ));
    }
}

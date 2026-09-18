<?php declare(strict_types=1);

namespace App\DTO\Flocage;

/**
 * Un article à floquer et les pièces qu'il porte.
 *
 * Le floqueur travaille carton par carton : il ouvre les t-shirts Erima jaunes, les marque
 * tous, passe au suivant. Le document suit ce geste — d'où le regroupement par article, avec
 * la référence catalogue qui permet d'identifier le carton sans connaître notre nomenclature.
 */
final class FlocageArticle
{
    /** @param list<FlocageLigne> $lignes */
    public function __construct(
        public readonly string $designation,
        public readonly ?string $refCatalogue,
        public readonly array $lignes,
        public readonly int $pieces,
    ) {}
}

<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

/**
 * Un kit de la saison : ce que le club s'est engagé à remettre, et à qui.
 */
final class JustificatifKit
{
    /**
     * @param list<string>               $cibles  noms d'affectation — « U11 », « Toute la saison »
     * @param list<JustificatifKitEntree> $entrees
     */
    public function __construct(
        public readonly string $nom,
        public readonly array $cibles,
        public readonly array $entrees,
        public readonly int $personnes,
    ) {}
}

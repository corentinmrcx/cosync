<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Une ligne de la chronologie affichée sur une fiche licencié ou dirigeant.
 */
final class EvenementHistorique
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $label,
        public readonly string $who,
        public readonly string $format = 'd/m/Y à H:i',
    ) {}
}

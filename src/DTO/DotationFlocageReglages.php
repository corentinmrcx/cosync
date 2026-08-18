<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Ce que le kit attend comme texte à floquer sur une ligne de dotation : le libellé de la
 * question et la longueur permise. Présent uniquement pour les articles réellement floqués —
 * son absence dit « cet article ne se floque pas », et le suivi n'y propose alors aucune saisie.
 */
final class DotationFlocageReglages
{
    public function __construct(
        public readonly string $label,
        public readonly int $maxLength,
    ) {}
}

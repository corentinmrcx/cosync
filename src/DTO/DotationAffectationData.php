<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationCibleType;

/**
 * Une attribution de kit porte sur *plusieurs* cibles d'un même type : un kit d'entraînement
 * concerne rarement une seule équipe, et le désigner ligne par ligne était le geste à refaire
 * autant de fois qu'il y avait d'équipes.
 */
final class DotationAffectationData
{
    /** @param list<string> $cibleIds vide pour la cible par défaut, qui ne désigne personne en particulier */
    public function __construct(
        public readonly DotationCibleType $cibleType,
        public readonly array $cibleIds,
    ) {}
}

<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Réponses du licencié à la partie « dotation » du formulaire public, validées.
 */
final class DotationChoixData
{
    public function __construct(
        /** @var array<string, int> { groupeChoix: stockItemId } */
        public readonly array $choix,
        /** @var array<string, string> { clé de personnalisation: texte à floquer } */
        public readonly array $personnalisation,
    ) {}
}

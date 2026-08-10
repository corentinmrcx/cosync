<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationEligibilite;

/**
 * Réglages d'une ligne de modèle de dotation, tels que saisis par l'admin :
 * qui y a droit, et faut-il demander un texte de personnalisation au licencié.
 */
final class DotationLigneReglagesData
{
    public function __construct(
        public readonly DotationEligibilite $eligibilite,
        public readonly bool $personnalisationRequise,
        public readonly ?string $personnalisationLabel,
        public readonly ?int $personnalisationMaxLength,
    ) {}
}

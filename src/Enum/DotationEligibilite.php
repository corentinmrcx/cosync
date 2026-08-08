<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Qui a droit à une ligne de dotation, selon la nature de sa licence.
 *
 * Permet de réserver une option à une population sans dupliquer tout le kit : par exemple
 * une veste imposée aux nouveaux licenciés là où les renouvellements choisissent entre
 * plusieurs articles.
 */
enum DotationEligibilite: string
{
    case TOUS = 'tous';
    case NOUVEAUX = 'nouveaux';
    case RENOUVELLEMENTS = 'renouvellements';

    public function label(): string
    {
        return match ($this) {
            self::TOUS => 'Tout le monde',
            self::NOUVEAUX => 'Nouveaux licenciés uniquement',
            self::RENOUVELLEMENTS => 'Renouvellements uniquement',
        };
    }

    /**
     * Une nature inconnue (null) est traitée comme un renouvellement — c'est le mode d'échec
     * sûr. Un nouveau qui conserverait le choix se corrige d'un clic ; un renouvellement
     * privé de son choix, lui, génère une réclamation et une dotation fausse. Les dirigeants,
     * qui n'ont pas de nature de licence, relèvent du même cas.
     */
    public function accepte(?NatureLicence $nature): bool
    {
        return match ($this) {
            self::TOUS => true,
            self::NOUVEAUX => $nature !== null && $nature->estNouveau(),
            self::RENOUVELLEMENTS => $nature === null || !$nature->estNouveau(),
        };
    }
}

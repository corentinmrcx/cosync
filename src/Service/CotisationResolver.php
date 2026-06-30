<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Licencie;

/**
 * Résout la cotisation (€) due par un licencié.
 * Règle : l'équipe prime ; à défaut d'équipe (ou d'équipe sans cotisation définie),
 * on applique la cotisation par défaut de la saison.
 */
final class CotisationResolver
{
    public function resolve(Licencie $licencie): int
    {
        $team = $licencie->getTeam();
        if ($team !== null && $team->getCotisation() !== null) {
            return $team->getCotisation();
        }

        return $licencie->getSeason()->getCotisationDefaut();
    }
}

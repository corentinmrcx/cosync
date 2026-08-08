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

    /**
     * Libellé à porter sur le virement. Centralisé ici parce qu'il est affiché à trois
     * endroits (formulaire, confirmation, mail) : une divergence rendrait les virements
     * impossibles à rapprocher des licenciés.
     */
    public function libelleVirement(Licencie $licencie): string
    {
        return sprintf(
            'COTISATION %s %s %s',
            $licencie->getNom(),
            $licencie->getPrenom(),
            $licencie->getSeason()->getLabel(),
        );
    }
}

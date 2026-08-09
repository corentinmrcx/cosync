<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\Entity\Dirigeant;
use App\Service\Document\DocumentRequirementResolver;

/**
 * Le dossier public d'un dirigeant est-il complet ?
 *
 * Deux moitiés : les informations portées par le dirigeant lui-même, que l'entité sait
 * juger seule, et les documents à signer, qui dépendent de la saison et du ciblage —
 * donc d'une requête. Ajouter une charte en cours de saison rend automatiquement
 * incomplets les dossiers concernés, sans recalcul ni migration de données.
 */
final class DirigeantDossierCompletion
{
    public function __construct(
        private readonly DocumentRequirementResolver $resolver,
    ) {}

    public function isComplete(Dirigeant $dirigeant): bool
    {
        return $dirigeant->isBaseFormComplete()
            && $this->resolver->manquantsPourDirigeant($dirigeant) === [];
    }
}

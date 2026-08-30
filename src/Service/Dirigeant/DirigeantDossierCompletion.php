<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\Entity\Dirigeant;
use App\Entity\Season;
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

    /**
     * Même verdict pour toute une population, en un nombre de requêtes fixe — pour les écrans
     * de liste. La règle n'est écrite qu'ici, dans les deux cas : la dupliquer chez l'appelant
     * ferait diverger la liste de la fiche.
     *
     * @param Dirigeant[] $dirigeants
     *
     * @return array<string, bool> indexé par uuid
     */
    public function isCompleteLot(Season $season, array $dirigeants): array
    {
        $manquants = $this->resolver->manquantsPourDirigeants($season, $dirigeants);

        $complets = [];
        foreach ($dirigeants as $dirigeant) {
            $uuid = (string) $dirigeant->getUuid();
            $complets[$uuid] = $dirigeant->isBaseFormComplete() && $manquants[$uuid] === [];
        }

        return $complets;
    }
}

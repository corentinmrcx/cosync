<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\DirigeantStatut;

/**
 * Où en est le dossier d'un dirigeant ? Lecture seule.
 *
 * L'ordre des règles est la règle. En particulier « validé » passe **avant** « licence
 * administrative » : cette dernière n'attend ni lien ni document, mais elle existe bien à la
 * FFF et le club la signe comme les autres — sans quoi une licence validée continuerait de
 * s'afficher comme si rien n'avait été fait.
 */
final class DirigeantStatutResolver
{
    public function __construct(
        private readonly DirigeantDossierCompletion $dossierCompletion,
    ) {}

    public function pour(Dirigeant $dirigeant): DirigeantStatut
    {
        return $this->depuis($dirigeant, $this->dossierCompletion->isComplete($dirigeant));
    }

    /**
     * Statuts de toute une population — pour les listes, où interroger la complétude ligne par
     * ligne coûterait deux requêtes par dirigeant.
     *
     * @param Dirigeant[] $dirigeants
     *
     * @return array<string, DirigeantStatut> indexé par uuid
     */
    public function pourLot(Season $season, array $dirigeants): array
    {
        $complets = $this->dossierCompletion->isCompleteLot($season, $dirigeants);

        $statuts = [];
        foreach ($dirigeants as $dirigeant) {
            $uuid = (string) $dirigeant->getUuid();
            $statuts[$uuid] = $this->depuis($dirigeant, $complets[$uuid]);
        }

        return $statuts;
    }

    private function depuis(Dirigeant $dirigeant, bool $dossierComplet): DirigeantStatut
    {
        if ($dirigeant->getValidatedFffAt() !== null) {
            return DirigeantStatut::VALIDE;
        }

        if ($dirigeant->isLicenceAdministrative()) {
            return DirigeantStatut::LICENCE_ADMINISTRATIVE;
        }

        if ($dossierComplet) {
            return DirigeantStatut::A_VALIDER_FFF;
        }

        // Le formulaire a bien été soumis : ce qui manque a donc été ajouté depuis, c'est un
        // document à faire signer, pas un dossier jamais rempli.
        if ($dirigeant->getFormCompletedAt() !== null) {
            return DirigeantStatut::DOCUMENT_A_SIGNER;
        }

        // `linkSentAt` et non le jeton : celui-ci est effacé dès le dossier complet, il ne sait
        // pas dire si la personne a été contactée un jour.
        return $dirigeant->getLinkSentAt() !== null
            ? DirigeantStatut::LIEN_ENVOYE
            : DirigeantStatut::LIEN_NON_ENVOYE;
    }
}

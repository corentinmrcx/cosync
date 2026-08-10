<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\Entity\Season;
use App\Repository\LicencieRepository;

/**
 * Données des licenciés servies au formulaire dirigeant : un dirigeant est souvent aussi
 * licencié, le formulaire propose alors de reprendre ce qu'il a déjà déclaré plutôt que
 * de lui redemander.
 */
final class DirigeantFormPrefill
{
    public function __construct(
        private readonly LicencieRepository $licencieRepository,
    ) {}

    /** @return array<string, array<string, string|null>> indexé par UUID de licencié */
    public function parUuid(Season $season): array
    {
        $parUuid = [];

        foreach ($this->licencieRepository->findBySeason($season) as $licencie) {
            $dossier = $licencie->getDossierClub();

            $parUuid[(string) $licencie->getUuid()] = [
                'nom' => $licencie->getNom(),
                'prenom' => $licencie->getPrenom(),
                'email' => $licencie->getEmail(),
                'telephone' => $licencie->getTelephone(),
                'dateNaissance' => $licencie->getDateNaissance()->format('Y-m-d'),
                'numLicence' => $licencie->getNumLicence(),
                'tailleHaut' => $dossier?->getTailleHaut(),
                'tailleBas' => $dossier?->getTailleBas(),
                'pointure' => $dossier?->getPointure(),
            ];
        }

        return $parUuid;
    }
}

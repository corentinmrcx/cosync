<?php declare(strict_types=1);

namespace App\Service\Effectif;

use App\DTO\Effectif\EffectifTableauDeBord;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;

/** Compteurs du hub Effectif. Lecture seule. */
final class EffectifPresenter
{
    public function __construct(
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
    ) {}

    public function getTableauDeBord(Season $season): EffectifTableauDeBord
    {
        $nbJoueurs = $this->licencieRepository->countWithFilters($season);

        return new EffectifTableauDeBord(
            $nbJoueurs,
            $this->dirigeantRepository->countBySeason($season),
            // VALIDATED est le seul statut qui signe un dossier bouclé : tout le reste
            // est en attente, sans distinguer l'étape qui manque.
            $nbJoueurs - $this->licencieRepository->countWithFilters($season, status: LicenceStatus::VALIDATED),
            $this->dirigeantRepository->countFormulairesEnAttente($season),
        );
    }
}

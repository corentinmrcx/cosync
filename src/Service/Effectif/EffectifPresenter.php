<?php declare(strict_types=1);

namespace App\Service\Effectif;

use App\DTO\Effectif\EffectifTableauDeBord;
use App\Entity\Season;
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
            // Le compteur mesure ce qui manque au club, donc l'argent et le formulaire :
            // un dossier soldé n'est plus « en attente » même s'il reste à valider dans
            // FootClubs, geste interne qui n'appelle aucune relance du licencié.
            $nbJoueurs - $this->licencieRepository->countSoldes($season),
            $this->dirigeantRepository->countFormulairesEnAttente($season),
        );
    }
}

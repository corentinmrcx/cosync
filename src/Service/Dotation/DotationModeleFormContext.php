<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationModele;
use App\Enum\DirigeantRole;
use App\Enum\DotationEligibilite;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DotationAffectationRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockItemRepository;
use App\Repository\TeamRepository;

/**
 * Rassemble ce qu'affiche la page d'édition d'un kit. Composer un kit et dire qui le reçoit
 * sont la même décision : les listes de destinataires possibles y sont donc servies avec
 * les articles.
 */
final class DotationModeleFormContext
{
    public function __construct(
        private readonly StockItemRepository $itemRepository,
        private readonly DotationAffectationRepository $affectationRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DotationModelePreview $preview,
    ) {}

    /** @return array<string, mixed> */
    public function build(DotationModele $modele): array
    {
        $season = $modele->getSeason();
        $affectations = $this->affectationRepository->findByModele($modele);

        return [
            'modele' => $modele,
            'articles' => $this->itemRepository->findAllOrdered(),
            'eligibilites' => DotationEligibilite::cases(),
            'personnalisationMaxDefaut' => DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
            'affectations' => $affectations,
            'apercu' => $this->preview->build($modele, $affectations),
            'categories' => $this->categoryRepository->findBy([], ['minYear' => 'ASC']),
            'teams' => $this->teamRepository->findBySeason($season),
            'licencies' => $this->licencieRepository->findValidatedBySeason($season),
            'dirigeants' => $this->dirigeantRepository->findBySeason($season),
            'roles' => DirigeantRole::cases(),
        ];
    }
}

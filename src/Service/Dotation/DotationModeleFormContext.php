<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationCibleOption;
use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Enum\DotationCibleType;
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
        $affectations = $this->affectationRepository->findByModele($modele);
        $deja = $this->cibleIdsParType($affectations);

        return [
            'modele' => $modele,
            'articles' => $this->itemRepository->findAllOrdered(),
            'eligibilites' => DotationEligibilite::cases(),
            'personnalisationMaxDefaut' => DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
            'affectations' => $affectations,
            'apercu' => $this->preview->build($modele, $affectations),
            'cibles' => $this->cibles($modele),
            'ciblesVerrouillees' => $deja,
            'defautAttribue' => $deja[DotationCibleType::DEFAUT->value] !== [],
        ];
    }

    /**
     * Les destinataires possibles, par type. Ceux que le kit dote déjà ne sont pas retirés
     * de la liste : ils y restent verrouillés (cf. `ciblesVerrouillees`), sans quoi une
     * équipe disparaîtrait du sélecteur sans qu'on sache pourquoi.
     *
     * @return array<string, list<DotationCibleOption>>
     */
    private function cibles(DotationModele $modele): array
    {
        $season = $modele->getSeason();

        return [
            DotationCibleType::TEAM->value => array_map(
                static fn (Team $t) => new DotationCibleOption((string) $t->getId(), $t->getName()),
                $this->teamRepository->findBySeason($season),
            ),
            DotationCibleType::CATEGORY->value => array_map(
                static fn (Category $c) => new DotationCibleOption((string) $c->getId(), $c->getLabel()),
                $this->categoryRepository->findAllOrdered(),
            ),
            DotationCibleType::ROLE->value => array_map(
                static fn (DirigeantRole $r) => new DotationCibleOption($r->value, $r->label()),
                DirigeantRole::cases(),
            ),
            DotationCibleType::LICENCIE->value => array_map(
                static fn (Licencie $l) => new DotationCibleOption((string) $l->getUuid(), $l->getNomPrenom()),
                $this->licencieRepository->findValidatedBySeason($season),
            ),
            DotationCibleType::DIRIGEANT->value => array_map(
                static fn (Dirigeant $d) => new DotationCibleOption((string) $d->getUuid(), $d->getNomPrenom()),
                $this->dirigeantRepository->findBySeason($season),
            ),
        ];
    }

    /**
     * Ce que ce kit dote déjà, par type de cible.
     *
     * @param DotationAffectation[] $affectations
     *
     * @return array<string, list<string>>
     */
    private function cibleIdsParType(array $affectations): array
    {
        $deja = array_fill_keys(array_column(DotationCibleType::cases(), 'value'), []);

        foreach ($affectations as $affectation) {
            $deja[$affectation->cibleType()->value][] = (string) $affectation->cibleId();
        }

        return $deja;
    }
}

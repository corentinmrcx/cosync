<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\StockItemVetementType;
use App\Repository\DotationAffectationRepository;

/**
 * Résout, pour une personne (licencié ou dirigeant), le modèle de dotation applicable
 * et la dotation détaillée (article + quantité + taille déduite).
 *
 * Priorité des affectations : individu > équipe > catégorie FFF > défaut saison.
 */
final class DotationResolver
{
    /** @var array<int, DotationAffectation[]> Cache des affectations par saison (scope requête) */
    private array $affectationsCache = [];

    public function __construct(
        private readonly DotationAffectationRepository $affectationRepository,
    ) {}

    public function resolveModele(Licencie|Dirigeant $person): ?DotationModele
    {
        $best = null;

        foreach ($this->affectationsForSeason($person->getSeason()) as $affectation) {
            if (!$this->matches($affectation, $person)) {
                continue;
            }
            if ($best === null || $affectation->priorite() > $best->priorite()) {
                $best = $affectation;
            }
        }

        return $best?->getModele();
    }

    /**
     * @return array<int, array{stockItem: \App\Entity\StockItem, quantite: int, obligatoire: bool, groupeChoix: ?string, taille: ?string}>
     */
    public function resolveDotation(Licencie|Dirigeant $person): array
    {
        $modele = $this->resolveModele($person);
        if ($modele === null) {
            return [];
        }

        $choix = $this->storedChoices($person);

        // Sépare les lignes simples des groupes de choix « 1 parmi N »
        $simples = [];
        $groupes = [];
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() !== null) {
                $groupes[$ligne->getGroupeChoix()][] = $ligne;
            } else {
                $simples[] = $ligne;
            }
        }

        // Pour chaque groupe : la ligne choisie (sinon la première par défaut)
        $retenues = $simples;
        foreach ($groupes as $groupe => $lignes) {
            $voulu = isset($choix[$groupe]) ? (int) $choix[$groupe] : null;
            $choisie = null;
            foreach ($lignes as $ligne) {
                if ($voulu !== null && $ligne->getStockItem()->getId() === $voulu) {
                    $choisie = $ligne;
                    break;
                }
            }
            $retenues[] = $choisie ?? $lignes[0];
        }

        $out = [];
        foreach ($retenues as $ligne) {
            $item = $ligne->getStockItem();
            $out[] = [
                'stockItem'   => $item,
                'quantite'    => $ligne->getQuantite(),
                'obligatoire' => $ligne->isObligatoire(),
                'groupeChoix' => $ligne->getGroupeChoix(),
                'taille'      => $this->sizeFor($person, $item->getTypeVetement()),
            ];
        }

        return $out;
    }

    /**
     * Groupes de choix « 1 parmi N » du modèle résolu, pour proposer un choix dans le formulaire public.
     *
     * @return array<int, array{groupe: string, options: \App\Entity\StockItem[]}>
     */
    public function getChoiceGroups(Licencie|Dirigeant $person): array
    {
        $modele = $this->resolveModele($person);
        if ($modele === null) {
            return [];
        }

        $groupes = [];
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() !== null) {
                $groupes[$ligne->getGroupeChoix()][] = $ligne->getStockItem();
            }
        }

        $out = [];
        foreach ($groupes as $groupe => $options) {
            $out[] = ['groupe' => $groupe, 'options' => $options];
        }

        return $out;
    }

    /** @return array<string, int> */
    private function storedChoices(Licencie|Dirigeant $person): array
    {
        if ($person instanceof Licencie) {
            return $person->getDossierClub()?->getDotationChoix() ?? [];
        }

        return [];
    }

    /** @return DotationAffectation[] */
    private function affectationsForSeason(Season $season): array
    {
        return $this->affectationsCache[$season->getId()] ??= $this->affectationRepository->findBySeason($season);
    }

    private function matches(DotationAffectation $affectation, Licencie|Dirigeant $person): bool
    {
        // Cible individuelle
        if ($affectation->getLicencie() !== null) {
            return $person instanceof Licencie
                && (string) $affectation->getLicencie()->getUuid() === (string) $person->getUuid();
        }
        if ($affectation->getDirigeant() !== null) {
            return $person instanceof Dirigeant
                && (string) $affectation->getDirigeant()->getUuid() === (string) $person->getUuid();
        }

        // Cible équipe
        if ($affectation->getTeam() !== null) {
            return $person->getTeam() !== null
                && $affectation->getTeam()->getId() === $person->getTeam()->getId();
        }

        // Cible catégorie FFF (licenciés uniquement)
        if ($affectation->getCategory() !== null) {
            return $person instanceof Licencie
                && $affectation->getCategory()->getId() === $person->getCategory()->getId();
        }

        // Affectation par défaut (sans cible)
        return true;
    }

    public function sizeFor(Licencie|Dirigeant $person, ?StockItemVetementType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($person instanceof Dirigeant) {
            return match ($type) {
                StockItemVetementType::HAUT       => $person->getTailleHaut(),
                StockItemVetementType::BAS        => $person->getTailleBas(),
                StockItemVetementType::CHAUSSURES => $person->getPointure(),
            };
        }

        $dossier = $person->getDossierClub();
        if ($dossier === null) {
            return null;
        }

        return match ($type) {
            StockItemVetementType::HAUT       => $dossier->getTailleHaut(),
            StockItemVetementType::BAS        => $dossier->getTailleBas(),
            StockItemVetementType::CHAUSSURES => $dossier->getPointure(),
        };
    }
}

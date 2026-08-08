<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\NatureLicence;
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
            // Un modèle désactivé ne dote plus personne, même s'il reste affecté : c'est ce
            // qui permet de préparer le kit de la saison suivante sans qu'il parte en production.
            if (!$affectation->getModele()->isActif()) {
                continue;
            }
            if (!$this->matches($affectation, $person)) {
                continue;
            }
            // >= et non > : à priorité égale, la dernière affectation créée l'emporte (le
            // repository trie par id croissant). C'est le modèle mental de l'admin, et
            // surtout le résultat devient reproductible.
            if ($best === null || $affectation->priorite() >= $best->priorite()) {
                $best = $affectation;
            }
        }

        return $best?->getModele();
    }

    /**
     * @return array<int, array{stockItem: \App\Entity\StockItem, quantite: int, obligatoire: bool, groupeChoix: ?string, taille: ?string, personnalisation: ?string}>
     */
    public function resolveDotation(Licencie|Dirigeant $person): array
    {
        $retenues = $this->retainedLines($person, $this->storedChoices($person));
        $textes = $this->storedPersonnalisations($person);

        $out = [];
        foreach ($retenues as $ligne) {
            $item = $ligne->getStockItem();
            $out[] = [
                'stockItem' => $item,
                'quantite' => $ligne->getQuantite(),
                'obligatoire' => $ligne->isObligatoire(),
                'groupeChoix' => $ligne->getGroupeChoix(),
                'taille' => $this->sizeFor($person, $item->getTypeVetement()),
                // Un texte périmé, laissé sur une option qui n'exige plus de personnalisation,
                // ne doit jamais remonter jusqu'au flocage.
                'personnalisation' => $ligne->isPersonnalisationRequise()
                    ? ($textes[$this->personnalisationKey($ligne)] ?? null)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Questions de choix à poser dans le formulaire public : uniquement les groupes qui
     * proposent au moins 2 options éligibles à cette personne. Un groupe dont une seule
     * option est éligible n'est pas une question — il est résolu automatiquement par
     * `resolveDotation()`. C'est ainsi qu'un nouveau licencié reçoit la veste sans qu'on
     * lui demande quoi que ce soit.
     *
     * Retourne les lignes du modèle, pas les articles : le formulaire a besoin des réglages
     * de personnalisation portés par la ligne.
     *
     * @return array<int, array{groupe: string, options: DotationModeleLigne[]}>
     */
    public function getChoiceGroups(Licencie|Dirigeant $person): array
    {
        $modele = $this->resolveModele($person);
        if ($modele === null) {
            return [];
        }

        $nature = $this->natureOf($person);
        $groupes = [];
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() === null || !$ligne->getEligibilite()->accepte($nature)) {
                continue;
            }
            $groupes[$ligne->getGroupeChoix()][] = $ligne;
        }

        $out = [];
        foreach ($groupes as $groupe => $options) {
            if (count($options) < 2) {
                continue;
            }
            $out[] = ['groupe' => $groupe, 'options' => $options];
        }

        return $out;
    }

    /** Un dirigeant n'a pas de nature de licence : il relève du cas « inconnu ». */
    private function natureOf(Licencie|Dirigeant $person): ?NatureLicence
    {
        return $person instanceof Licencie ? $person->getNatureLicence() : null;
    }

    /** @return array<string, int> */
    private function storedChoices(Licencie|Dirigeant $person): array
    {
        if ($person instanceof Licencie) {
            return $person->getDossierClub()?->getDotationChoix() ?? [];
        }

        return [];
    }

    /** @return array<string, string> */
    private function storedPersonnalisations(Licencie|Dirigeant $person): array
    {
        if ($person instanceof Licencie) {
            return $person->getDossierClub()?->getDotationPersonnalisation() ?? [];
        }

        return [];
    }

    /**
     * Clé sous laquelle le texte d'une ligne est stocké dans le dossier : le nom du groupe
     * pour une option de choix, « ligne:<id> » pour un article fixe personnalisé. Le préfixe
     * écarte toute collision avec un groupe qui s'appellerait littéralement « 12 ».
     */
    public function personnalisationKey(DotationModeleLigne $ligne): string
    {
        return $ligne->getGroupeChoix() ?? 'ligne:' . $ligne->getId();
    }

    /**
     * Lignes retenues qui réclament un texte du licencié, compte tenu des choix faits.
     * Couvre les groupes auto-résolus (une seule option éligible) et les articles fixes :
     * un article floqué doit être saisi même quand aucune question de choix n'est posée.
     *
     * @param  array<string, int> $choix { groupeChoix: stockItemId }
     * @return array<int, array{cle: string, ligne: DotationModeleLigne}>
     */
    public function getPersonnalisationRequests(Licencie|Dirigeant $person, array $choix): array
    {
        $out = [];
        foreach ($this->retainedLines($person, $choix) as $ligne) {
            if ($ligne->isPersonnalisationRequise()) {
                $out[] = ['cle' => $this->personnalisationKey($ligne), 'ligne' => $ligne];
            }
        }

        return $out;
    }

    /**
     * Textes dus alors qu'aucune question de choix n'est posée : groupe auto-résolu (une seule
     * option éligible) ou article fixe personnalisé. C'est ce que le formulaire public doit
     * demander « à part ».
     *
     * Impossible de réutiliser `getPersonnalisationRequests($person, [])` tel quel : sans choix,
     * `retainedLines()` retient par repli la première option de CHAQUE groupe, y compris ceux
     * qui sont déjà posés en question — le formulaire affichait alors deux fois le même champ,
     * sous la même clé.
     *
     * @return array<int, array{cle: string, ligne: DotationModeleLigne}>
     */
    public function getAutoPersonnalisationRequests(Licencie|Dirigeant $person): array
    {
        $questions = array_column($this->getChoiceGroups($person), 'groupe');

        return array_values(array_filter(
            $this->getPersonnalisationRequests($person, []),
            static fn (array $demande): bool => !in_array($demande['cle'], $questions, true),
        ));
    }

    /**
     * Lignes du kit effectivement dues à cette personne : articles fixes + une option par
     * groupe de choix. L'éligibilité s'applique aux deux — une ligne fixe peut être réservée
     * aux nouveaux licenciés. Un groupe sans aucune option éligible n'est pas dû du tout.
     *
     * @param  array<string, int> $choix { groupeChoix: stockItemId }
     * @return DotationModeleLigne[]
     */
    private function retainedLines(Licencie|Dirigeant $person, array $choix): array
    {
        $modele = $this->resolveModele($person);
        if ($modele === null) {
            return [];
        }

        $nature = $this->natureOf($person);
        $simples = [];
        $groupes = [];
        foreach ($modele->getLignes() as $ligne) {
            if (!$ligne->getEligibilite()->accepte($nature)) {
                continue;
            }
            if ($ligne->getGroupeChoix() !== null) {
                $groupes[$ligne->getGroupeChoix()][] = $ligne;
            } else {
                $simples[] = $ligne;
            }
        }

        // Pour chaque groupe : la ligne choisie si elle est encore éligible, sinon la
        // première option éligible. Couvre le cas d'un choix devenu invalide après une
        // correction de la nature de licence par l'admin.
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

        return $retenues;
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

        // Cible rôle dirigeant : le pendant de la catégorie côté encadrement — un responsable,
        // un coach et un dirigeant standard n'ont pas la même dotation.
        if ($affectation->getRole() !== null) {
            return $person instanceof Dirigeant && $affectation->getRole() === $person->getRole();
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
                StockItemVetementType::HAUT => $person->getTailleHaut(),
                StockItemVetementType::BAS => $person->getTailleBas(),
                StockItemVetementType::CHAUSSURES => $person->getPointure(),
            };
        }

        $dossier = $person->getDossierClub();
        if ($dossier === null) {
            return null;
        }

        return match ($type) {
            StockItemVetementType::HAUT => $dossier->getTailleHaut(),
            StockItemVetementType::BAS => $dossier->getTailleBas(),
            StockItemVetementType::CHAUSSURES => $dossier->getPointure(),
        };
    }
}

<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Stock\StockAlerte;
use App\DTO\Stock\StockInventaireLigne;
use App\DTO\Stock\StockLigne;
use App\DTO\Stock\StockSection;
use App\DTO\Stock\StockTableauDeBord;
use App\DTO\Stock\StockTailleLigne;
use App\Entity\StockItem;
use App\Enum\StockAlerteNiveau;
use App\Repository\StockCategoryRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
use App\Service\Referentiel\TailleReferentiel;

/**
 * États du stock destinés à l'affichage : tableau de gestion, tableau de bord, inventaire.
 * Lecture seule.
 */
final class StockReportService
{
    public function __construct(
        private readonly StockItemRepository $itemRepository,
        private readonly StockCategoryRepository $categoryRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockTailleNoteRepository $noteRepository,
        private readonly StockTailleResolver $taillesResolver,
        private readonly TailleReferentiel $referentiel,
    ) {}

    /** @return list<StockSection<StockLigne>> */
    public function getStockSummary(bool $includeArchived = false): array
    {
        return $this->parCategorie(
            $this->itemRepository->findAllOrdered($includeArchived),
            $this->ligneDeGestion(...),
        );
    }

    /** @return list<StockSection<StockInventaireLigne>> */
    public function getInventaireData(): array
    {
        return $this->parCategorie(
            $this->itemRepository->findAllOrdered(),
            $this->ligneDInventaire(...),
        );
    }

    public function getDashboardData(): StockTableauDeBord
    {
        $items = $this->itemRepository->findAllOrdered();

        $alertes = [];
        $nbRuptures = 0;
        $valeurStock = 0.0;

        foreach ($items as $item) {
            $stock = $this->movementRepository->getCurrentStock($item);

            if ($item->getPrixAchat() !== null && $stock > 0) {
                $valeurStock += $stock * $item->getPrixAchat();
            }

            $niveau = StockAlerteNiveau::pour($stock, $item->getAlertSeuil());
            if (!$niveau->estAlerte()) {
                continue;
            }

            $alertes[] = new StockAlerte($item, $stock, $niveau);
            if ($niveau === StockAlerteNiveau::RUPTURE) {
                ++$nbRuptures;
            }
        }

        usort($alertes, $this->rupturesEnTete(...));

        return new StockTableauDeBord(
            count($items),
            // Stock bas uniquement : une rupture n'est pas comptée deux fois.
            count($alertes) - $nbRuptures,
            $nbRuptures,
            $valeurStock,
            $alertes,
        );
    }

    /**
     * Regroupe les articles par catégorie, dans l'ordre des catégories, les articles sans
     * catégorie fermant la liste.
     *
     * @template T of StockLigne|StockInventaireLigne
     *
     * @param StockItem[]           $items
     * @param callable(StockItem): T $ligne
     *
     * @return list<StockSection<T>>
     */
    private function parCategorie(array $items, callable $ligne): array
    {
        $parCategorie = [];
        foreach ($items as $item) {
            $parCategorie[$item->getCategory()?->getId() ?? 0][] = $item;
        }

        $sections = [];
        foreach ($this->categoryRepository->findAllOrderedByPosition() as $category) {
            $articles = $parCategorie[$category->getId()] ?? [];
            if ($articles !== []) {
                $sections[] = new StockSection($category, array_map($ligne, $articles));
            }
        }

        if (($parCategorie[0] ?? []) !== []) {
            $sections[] = new StockSection(null, array_map($ligne, $parCategorie[0]));
        }

        return $sections;
    }

    /**
     * Tailles proposées pour chaque article, indexées par identifiant. L'historique s'en
     * sert pour corriger un mouvement sans quitter l'écran ; les articles répétés d'une
     * page à l'autre ne sont interrogés qu'une fois.
     *
     * @param StockItem[] $items
     *
     * @return array<int, list<string>>
     */
    public function taillesParArticle(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            if (isset($options[$item->getId()])) {
                continue;
            }

            $dejaUtilisees = array_map('strval', array_keys($this->movementRepository->getStockGroupedByTaille($item)));
            $options[$item->getId()] = $this->taillesResolver->options($item, $dejaUtilisees);
        }

        return $options;
    }

    private function ligneDeGestion(StockItem $item): StockLigne
    {
        $tailles = $this->ventilationParTaille($item);

        $taillesMap = [];
        foreach ($tailles as $ligne) {
            if (!$ligne->sansTaille()) {
                $taillesMap[$ligne->taille] = $ligne->stock;
            }
        }

        $stock = $this->movementRepository->getCurrentStock($item);

        return new StockLigne(
            $item,
            $stock,
            $this->niveau($item, $stock),
            $tailles,
            $taillesMap,
            // strval : une pointure « 42 » devient une clé entière en PHP, la comparaison au
            // référentiel se fait sur des chaînes.
            $this->taillesResolver->options($item, array_map('strval', array_keys($taillesMap))),
            $this->taillesResolver->typeVetementARenseigner($item),
        );
    }

    private function ligneDInventaire(StockItem $item): StockInventaireLigne
    {
        $tailles = $this->ventilationParTaille($item)
            ?: [new StockTailleLigne(StockTailleLigne::SANS_TAILLE, 0)];

        $stock = $this->movementRepository->getCurrentStock($item);

        return new StockInventaireLigne($item, $stock, $this->niveau($item, $stock), $tailles);
    }

    private function niveau(StockItem $item, int $stock): StockAlerteNiveau
    {
        return StockAlerteNiveau::pour($stock, $item->getAlertSeuil());
    }

    /**
     * Ventilation du stock par taille, dans l'ordre du référentiel — un tri alphabétique
     * rangerait le 128 avant le 8 ans et la pointure 40 avant la 5. La clé vide (article
     * sans taille) s'affiche « — » et ouvre la liste.
     *
     * @return list<StockTailleLigne>
     */
    private function ventilationParTaille(StockItem $item): array
    {
        $parTaille = $this->movementRepository->getStockGroupedByTaille($item);
        uksort($parTaille, $this->ordreDesTailles(...));

        $notes = $this->noteRepository->parTaille($item);

        $lignes = [];
        foreach ($parTaille as $taille => $stock) {
            $taille = (string) $taille;
            $lignes[] = new StockTailleLigne(
                $taille === '' ? StockTailleLigne::SANS_TAILLE : $taille,
                $stock,
                $notes[$taille] ?? null,
            );
        }

        return $lignes;
    }

    /** La clé vide n'est pas une taille : elle passe devant sans traverser le référentiel. */
    private function ordreDesTailles(string|int $a, string|int $b): int
    {
        $a = (string) $a;
        $b = (string) $b;

        if ($a === '' || $b === '') {
            return ($a === '' ? 0 : 1) <=> ($b === '' ? 0 : 1);
        }

        return $this->referentiel->comparer($a, $b);
    }

    private function rupturesEnTete(StockAlerte $a, StockAlerte $b): int
    {
        $rang = static fn (StockAlerte $alerte): int => $alerte->niveau === StockAlerteNiveau::RUPTURE ? 0 : 1;

        return $rang($a) <=> $rang($b);
    }
}

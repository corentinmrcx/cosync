<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockAlerteNiveau;
use App\Repository\StockCategoryRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;

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
    ) {}

    /**
     * @return array<int, array{category: \App\Entity\StockCategory|null, items: array<int, array<string, mixed>>}>
     */
    public function getStockSummary(bool $includeArchived = false): array
    {
        return $this->parCategorie(
            $this->itemRepository->findAllOrdered($includeArchived),
            $this->ligneAvecTailles(...),
        );
    }

    /**
     * Compteurs et articles en alerte pour le tableau de bord.
     *
     * @return array{
     *   nbArticles: int,
     *   nbAlertes: int,
     *   nbRuptures: int,
     *   valeurStock: float,
     *   alertes: array<int, array{item: StockItem, stock: int, status: string}>
     * }
     */
    public function getDashboardData(): array
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

            $alertes[] = ['item' => $item, 'stock' => $stock, 'status' => $niveau->value];
            if ($niveau === StockAlerteNiveau::RUPTURE) {
                ++$nbRuptures;
            }
        }

        usort($alertes, $this->rupturesEnTete(...));

        return [
            'nbArticles' => count($items),
            // Stock bas uniquement : une rupture n'est pas comptée deux fois.
            'nbAlertes' => count($alertes) - $nbRuptures,
            'nbRuptures' => $nbRuptures,
            'valeurStock' => $valeurStock,
            'alertes' => $alertes,
        ];
    }

    /**
     * État complet pour la feuille d'inventaire : une ligne par taille présente en stock,
     * ou une seule ligne « — » pour les articles sans taille.
     *
     * @return array<int, array{category: \App\Entity\StockCategory|null, items: array<int, array<string, mixed>>}>
     */
    public function getInventaireData(): array
    {
        return $this->parCategorie(
            $this->itemRepository->findAllOrdered(),
            $this->ligneInventaire(...),
        );
    }

    /**
     * Regroupe les articles par catégorie, dans l'ordre des catégories, les articles sans
     * catégorie fermant la liste.
     *
     * @param StockItem[]                            $items
     * @param callable(StockItem): array<string, mixed> $ligne
     *
     * @return array<int, array{category: \App\Entity\StockCategory|null, items: array<int, array<string, mixed>>}>
     */
    private function parCategorie(array $items, callable $ligne): array
    {
        $parCategorie = [];
        foreach ($items as $item) {
            $parCategorie[$item->getCategory()?->getId() ?? 0][] = $item;
        }

        $regroupes = [];
        foreach ($this->categoryRepository->findAllOrderedByPosition() as $category) {
            $articles = $parCategorie[$category->getId()] ?? [];
            if ($articles !== []) {
                $regroupes[] = ['category' => $category, 'items' => array_map($ligne, $articles)];
            }
        }

        if (($parCategorie[0] ?? []) !== []) {
            $regroupes[] = ['category' => null, 'items' => array_map($ligne, $parCategorie[0])];
        }

        return $regroupes;
    }

    /**
     * Ligne du tableau de gestion. `taillesMap` (taille → stock) alimente la modale de mouvement.
     *
     * @return array{item: StockItem, stock: int, status: string, tailles: array<int, array{taille: string, stock: int}>, hasTailles: bool, taillesMap: array<string, int>}
     */
    private function ligneAvecTailles(StockItem $item): array
    {
        $tailles = $this->ventilationParTaille($item);

        $taillesMap = [];
        foreach ($tailles as $ligne) {
            if ($ligne['taille'] !== '—') {
                $taillesMap[$ligne['taille']] = $ligne['stock'];
            }
        }

        return $this->ligne($item) + [
            'tailles' => $tailles,
            'hasTailles' => $taillesMap !== [],
            'taillesMap' => $taillesMap,
        ];
    }

    /**
     * @return array{item: StockItem, total: int, status: string, tailles: array<int, array{taille: string, stock: int}>}
     */
    private function ligneInventaire(StockItem $item): array
    {
        $tailles = $this->ventilationParTaille($item) ?: [['taille' => '—', 'stock' => 0]];
        $ligne = $this->ligne($item);

        return ['item' => $item, 'total' => $ligne['stock'], 'status' => $ligne['status'], 'tailles' => $tailles];
    }

    /** @return array{item: StockItem, stock: int, status: string} */
    private function ligne(StockItem $item): array
    {
        $stock = $this->movementRepository->getCurrentStock($item);

        return [
            'item' => $item,
            'stock' => $stock,
            'status' => StockAlerteNiveau::pour($stock, $item->getAlertSeuil())->value,
        ];
    }

    /**
     * Ventilation du stock par taille, triée. La clé vide (article sans taille) s'affiche « — ».
     *
     * @return array<int, array{taille: string, stock: int}>
     */
    private function ventilationParTaille(StockItem $item): array
    {
        $parTaille = $this->movementRepository->getStockGroupedByTaille($item);
        ksort($parTaille);

        $lignes = [];
        foreach ($parTaille as $taille => $stock) {
            $lignes[] = ['taille' => $taille === '' ? '—' : $taille, 'stock' => $stock];
        }

        return $lignes;
    }

    /**
     * @param array{status: string} $a
     * @param array{status: string} $b
     */
    private function rupturesEnTete(array $a, array $b): int
    {
        $rang = static fn (array $alerte): int => $alerte['status'] === StockAlerteNiveau::RUPTURE->value ? 0 : 1;

        return $rang($a) <=> $rang($b);
    }
}

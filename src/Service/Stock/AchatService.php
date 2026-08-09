<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Fournisseur;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;

/**
 * Calcule le « à commander » par fournisseur :
 *   à commander(article, taille) = max(0, besoins « à donner » − stock réel − commandes en attente).
 */
final class AchatService
{
    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly CommandeLigneRepository $commandeLigneRepository,
    ) {}

    /** Nombre total d'articles à commander, toutes lignes confondues. */
    public function compterACommander(Season $season): int
    {
        $total = 0;
        foreach ($this->computeACommander($season) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                $total += $ligne['aCommander'];
            }
        }

        return $total;
    }

    /**
     * @return array<int, array{
     *   fournisseur: ?Fournisseur,
     *   fournisseurNom: string,
     *   lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}>
     * }>
     */
    public function computeACommander(Season $season): array
    {
        // 1) Agrège les besoins « à donner » par (article, taille)
        /** @var array<string, array{item: StockItem, taille: ?string, besoin: int}> $agg */
        $agg = [];
        foreach ($this->besoinRepository->findADonnerBySeason($season) as $besoin) {
            $item = $besoin->getStockItem();
            $key = $item->getId() . '|' . ($besoin->getTaille() ?? '');
            if (!isset($agg[$key])) {
                $agg[$key] = ['item' => $item, 'taille' => $besoin->getTaille(), 'besoin' => 0];
            }
            $agg[$key]['besoin'] += $besoin->getQuantite();
        }

        $pending = $this->commandeLigneRepository->sumPendingByItemTaille();

        /** @var array<int, array<string, int>> $stockCache */
        $stockCache = [];
        /** @var array<string, array{fournisseur: ?Fournisseur, fournisseurNom: string, lignes: array<int, array<string, mixed>>}> $groupes */
        $groupes = [];

        foreach ($agg as $key => $row) {
            $item = $row['item'];
            $taille = $row['taille'];

            $stockCache[$item->getId()] ??= $this->movementRepository->getStockGroupedByTaille($item);
            $stock = $stockCache[$item->getId()][$taille ?? ''] ?? 0;
            $enAttente = $pending[$key] ?? 0;

            $aCommander = $row['besoin'] - $stock - $enAttente;
            if ($aCommander <= 0) {
                continue;
            }

            $fournisseur = $item->getFournisseur();
            $fKey = $fournisseur !== null ? (string) $fournisseur->getId() : '0';
            if (!isset($groupes[$fKey])) {
                $groupes[$fKey] = [
                    'fournisseur' => $fournisseur,
                    'fournisseurNom' => $fournisseur?->getNom() ?? 'Sans fournisseur',
                    'lignes' => [],
                ];
            }

            $groupes[$fKey]['lignes'][] = [
                'stockItem' => $item,
                'taille' => $taille,
                'besoin' => $row['besoin'],
                'stock' => $stock,
                'enAttente' => $enAttente,
                'aCommander' => $aCommander,
            ];
        }

        return array_values($groupes);
    }
}

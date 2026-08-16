<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Fournisseur;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;

/**
 * Ce qu'il reste à acheter pour honorer les dotations de la saison.
 *
 * Un article n'est à commander que si les besoins dépassent ce qui est déjà en stock
 * et ce qui est déjà commandé : sans cette soustraction, le club commanderait deux fois.
 */
final class AchatService
{
    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly CommandeLigneRepository $commandeLigneRepository,
    ) {}

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
     * Lignes à commander, regroupées par fournisseur — un bon de commande par fournisseur.
     *
     * @return array<int, array{
     *   fournisseur: ?Fournisseur,
     *   fournisseurNom: string,
     *   lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}>
     * }>
     */
    public function computeACommander(Season $season): array
    {
        $dejaCommande = $this->commandeLigneRepository->sumPendingByItemTaille();
        $stockParArticle = [];
        $groupes = [];

        foreach ($this->besoinsParArticleEtTaille($season) as $cle => $besoin) {
            $item = $besoin['item'];
            $taille = $besoin['taille'];

            $stockParArticle[$item->getId()] ??= $this->movementRepository->getStockGroupedByTaille($item);
            $stock = $stockParArticle[$item->getId()][$taille ?? ''] ?? 0;
            $enAttente = $dejaCommande[$cle] ?? 0;

            $aCommander = $besoin['besoin'] - $stock - $enAttente;
            if ($aCommander <= 0) {
                continue;
            }

            $groupes = $this->ajouterLigne($groupes, $item, [
                'stockItem' => $item,
                'taille' => $taille,
                'besoin' => $besoin['besoin'],
                'stock' => $stock,
                'enAttente' => $enAttente,
                'aCommander' => $aCommander,
            ]);
        }

        return array_values($groupes);
    }

    /**
     * Besoins « à donner » cumulés par couple (article, taille) : deux licenciés qui
     * attendent la même veste en L ne font qu'une ligne de commande.
     *
     * C'est l'article **servi** qui compte, pas celui du kit : une ligne couverte par un stock
     * en cours d'écoulement se cumule sous l'ancien article, dont le stock l'absorbe — et le
     * club ne rachète pas du neuf par-dessus. L'allocateur ne substituant jamais au-delà du
     * stock, ces lignes-là se soldent d'elles-mêmes et ne remontent pas au bon de commande.
     *
     * @return array<string, array{item: StockItem, taille: ?string, besoin: int}>
     */
    private function besoinsParArticleEtTaille(Season $season): array
    {
        $cumul = [];

        foreach ($this->besoinRepository->findADonnerBySeason($season) as $besoin) {
            $item = $besoin->getArticleServi();
            $cle = $item->getId() . '|' . ($besoin->getTaille() ?? '');

            $cumul[$cle] ??= ['item' => $item, 'taille' => $besoin->getTaille(), 'besoin' => 0];
            $cumul[$cle]['besoin'] += $besoin->getQuantite();
        }

        return $cumul;
    }

    /**
     * @param array<string, array<string, mixed>> $groupes
     * @param array<string, mixed>                $ligne
     *
     * @return array<string, array<string, mixed>>
     */
    private function ajouterLigne(array $groupes, StockItem $item, array $ligne): array
    {
        $fournisseur = $item->getFournisseur();
        // Préfixe volontaire : PHP convertirait une clé « 12 » en entier, et le
        // regroupement par fournisseur perdrait son typage.
        $cle = 'fournisseur-' . ($fournisseur?->getId() ?? 'aucun');

        if (!isset($groupes[$cle])) {
            $groupes[$cle] = [
                'fournisseur' => $fournisseur,
                'fournisseurNom' => $fournisseur?->getNom() ?? 'Sans fournisseur',
                'lignes' => [],
            ];
        }

        $groupes[$cle]['lignes'][] = $ligne;

        return $groupes;
    }
}

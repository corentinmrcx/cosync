<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Fournisseur;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Referentiel\TailleReferentiel;

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
        private readonly TailleReferentiel $tailles,
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

        return $this->ordonner(array_values($groupes));
    }

    /**
     * L'ordre dans lequel ces lignes se relisent : fournisseurs alphabétiques — « Sans
     * fournisseur » en fin de liste, comme partout où un groupe fourre-tout ferme un
     * classement —, articles par désignation, déclinaisons dans l'ordre du référentiel.
     *
     * Le cumul par clé rendait l'ordre des besoins rencontrés : deux S entre deux M, un
     * article revenant trois fois dans la page. Ça se trie ici et pas dans l'écran, parce
     * que le bon de commande recopie ces lignes telles quelles — la feuille qu'on a sous
     * les yeux chez le fournisseur doit se lire comme l'écran qui l'a produite.
     *
     * @param list<array{fournisseur: ?Fournisseur, fournisseurNom: string, lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}>}> $groupes
     *
     * @return array<int, array{fournisseur: ?Fournisseur, fournisseurNom: string, lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}>}>
     */
    private function ordonner(array $groupes): array
    {
        usort($groupes, static fn (array $a, array $b): int => [
            $a['fournisseur'] === null ? 1 : 0,
            mb_strtolower($a['fournisseurNom']),
        ] <=> [
            $b['fournisseur'] === null ? 1 : 0,
            mb_strtolower($b['fournisseurNom']),
        ]);

        foreach ($groupes as $i => $groupe) {
            $lignes = $groupe['lignes'];
            usort($lignes, $this->comparerLignes(...));
            $groupes[$i]['lignes'] = $lignes;
        }

        return $groupes;
    }

    /**
     * @param array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int} $a
     * @param array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int} $b
     */
    private function comparerLignes(array $a, array $b): int
    {
        $parNom = strnatcasecmp($a['stockItem']->getDesignation(), $b['stockItem']->getDesignation());
        if ($parNom !== 0) {
            return $parNom;
        }

        // Deux articles peuvent porter la même désignation sans être le même carton :
        // l'identifiant départage, pour que leurs tailles ne s'entremêlent pas.
        $parArticle = $a['stockItem']->getId() <=> $b['stockItem']->getId();

        // Le référentiel ordonne les tailles : « L, M, S, XL » n'aurait été l'ordre de personne.
        return $parArticle !== 0
            ? $parArticle
            : $this->tailles->comparer($a['taille'] ?? '', $b['taille'] ?? '');
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

        foreach ($this->besoinRepository->findNonRemisBySeason($season) as $besoin) {
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

<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Fournisseur;
use App\Entity\StockItem;
use App\Repository\StockMovementRepository;
use App\Service\Referentiel\TailleReferentiel;

/**
 * Pourquoi le stock d'une ligne à commander est introuvable.
 *
 * Une ligne « 46 · stock 0 · à commander 3 » ne dit pas si le club n'a rien, ou s'il a de
 * quoi servir rangé sous une autre étiquette. Le club a commandé 80 articles au lieu de 55
 * sur cette seule ambiguïté : les chaussettes déclarées en « 46 » dormaient en « 44 », le
 * libellé Erima du 44-46, parce qu'aucun article ne portait la grille qui les traduit.
 *
 * Lecture seule : le calcul reste celui d'{@see AchatService}, on ne fait que l'expliquer.
 */
final class AchatMotifPresenter
{
    public function __construct(
        private readonly StockMovementRepository $movementRepository,
        private readonly TailleReferentiel $referentiel,
        private readonly StockTaillePresenter $etiquettes,
    ) {}

    /**
     * Reprend les groupes d'{@see AchatService::computeACommander()} en ajoutant à chaque
     * ligne son motif, quand il y en a un.
     *
     * @param array<int, array{
     *   fournisseur: ?Fournisseur,
     *   fournisseurNom: string,
     *   lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}>
     * }> $groupes
     *
     * @return array<int, array{
     *   fournisseur: ?Fournisseur,
     *   fournisseurNom: string,
     *   lignes: array<int, array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int, motif: ?string, sansGrille: bool}>
     * }>
     */
    public function decorer(array $groupes): array
    {
        /** @var array<int, list<string>> $ailleurs */
        $ailleurs = [];

        foreach ($groupes as $i => $groupe) {
            foreach ($groupe['lignes'] as $j => $ligne) {
                $item = $ligne['stockItem'];
                $ailleurs[$item->getId()] ??= $this->libellesEnStock($item);

                $groupes[$i]['lignes'][$j] = $ligne + $this->motif($ligne, $ailleurs[$item->getId()]);
            }
        }

        return $groupes;
    }

    /**
     * Le motif ne se déclenche que sur le cas actionnable : rien dans la taille demandée,
     * mais du stock sous d'autres libellés. Un article dont le club n'a réellement rien est
     * à commander sans mystère — l'expliquer noierait les lignes qui ont quelque chose à dire.
     *
     * @param array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int} $ligne
     * @param list<string>                                                                                          $ailleurs
     *
     * @return array{motif: ?string, sansGrille: bool}
     */
    private function motif(array $ligne, array $ailleurs): array
    {
        $item = $ligne['stockItem'];
        $taille = $ligne['taille'];
        $autres = array_values(array_filter($ailleurs, static fn (string $l): bool => $l !== $taille));

        if ($taille === null || $ligne['stock'] > 0 || $autres === []) {
            return ['motif' => null, 'sansGrille' => false];
        }

        // En étiquettes de carton, comme la colonne Taille : « rangé en 44 » se lisait comme une
        // pointure, alors que c'est le carton Erima 44-46.
        $carton = fn (string $libelle): string => $this->etiquettes->etiquette($item, $libelle);

        return [
            'motif' => sprintf(
                'Aucun stock en « %s » — cet article est rangé en %s.',
                $carton($taille),
                implode(', ', array_map($carton, $autres)),
            ),
            // Cause n°1 : sans grille, la taille déclarée n'est jamais traduite en étiquette
            // fournisseur, et la ligne réclame une déclinaison qui n'existe à aucun carton.
            'sansGrille' => $ligne['stockItem']->getGrilleTaille() === null,
        ];
    }

    /**
     * Libellés sous lesquels l'article a réellement du stock, dans l'ordre du référentiel.
     * La clé vide (mouvement sans taille) n'en est pas un : elle ne désigne aucun rayon.
     *
     * @return list<string>
     */
    private function libellesEnStock(StockItem $item): array
    {
        $libelles = [];

        foreach ($this->movementRepository->getStockGroupedByTaille($item) as $libelle => $quantite) {
            if ($libelle !== '' && $quantite > 0) {
                $libelles[] = (string) $libelle;
            }
        }

        usort($libelles, $this->referentiel->comparer(...));

        return $libelles;
    }
}

<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Stock\AchatArticle;
use App\DTO\Stock\AchatFournisseur;
use App\DTO\Stock\AchatTaille;
use App\Entity\Season;
use App\Entity\StockItem;

/**
 * Met le « à commander » en forme pour l'écran : un article, ses tailles dessous, une
 * quantité.
 *
 * À plat, la même veste revenait quatre fois dans la page sans qu'aucune ligne ne dise
 * combien le club en achète en tout — impossible à relire en face d'un devis. Regroupé, le
 * cumul est sur la ligne d'article et le détail juste en dessous.
 *
 * **Une seule quantité en sort**, celle qu'on commande. Le besoin, le stock et ce qui est
 * déjà en route entrent dans le calcul mais ne ressortent pas : ce sont des faits
 * d'inventaire, et ils ont leur écrit — le justificatif de commande. Cet écran-ci sert à
 * commander, et une colonne de plus y est une question de plus.
 *
 * **Rien n'est recalculé ni retrié** : l'ordre et les nombres sont ceux
 * d'{@see AchatService::computeACommander()}, ceux-là mêmes que le bon de commande recopie.
 * Un écran qui referait la somme finirait par annoncer un chiffre que le bon dément.
 *
 * Lecture seule. L'arbitrage de l'écoulement doit avoir eu lieu avant l'appel, comme pour
 * tout lecteur des achats.
 */
final class AchatPresenter
{
    public function __construct(
        private readonly AchatService $achatService,
        private readonly StockTaillePresenter $etiquettes,
    ) {}

    /** @return list<AchatFournisseur> */
    public function parFournisseur(Season $season): array
    {
        $fournisseurs = [];

        foreach ($this->achatService->computeACommander($season) as $groupe) {
            $articles = [];
            $total = 0;

            foreach ($groupe['lignes'] as $ligne) {
                // Les lignes arrivent triées : celles d'un même article se suivent, et les
                // ranger par identifiant garde l'ordre des désignations.
                $articles[$ligne['stockItem']->getId()][] = $ligne;
                $total += $ligne['aCommander'];
            }

            $fournisseurs[] = new AchatFournisseur(
                $groupe['fournisseurNom'],
                array_map($this->article(...), array_values($articles)),
                $total,
            );
        }

        return $fournisseurs;
    }

    /**
     * @param non-empty-list<array{stockItem: StockItem, taille: ?string, besoin: int, stock: int, enAttente: int, aCommander: int}> $lignes
     */
    private function article(array $lignes): AchatArticle
    {
        $item = $lignes[0]['stockItem'];
        $tailles = [];
        $quantite = 0;

        foreach ($lignes as $ligne) {
            $tailles[] = new AchatTaille(
                // L'étiquette du carton, jamais le libellé du stock : un « 37 » seul se
                // lirait comme une pointure là où le fournisseur vend du 37-40 (§7 ter).
                $ligne['taille'] !== null ? $this->etiquettes->etiquette($item, $ligne['taille']) : null,
                $ligne['aCommander'],
            );

            $quantite += $ligne['aCommander'];
        }

        return new AchatArticle($item, $tailles, $quantite);
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationProvenance;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Service\Dotation\DotationProvenanceResolver;

/**
 * Le suivi doit dire à celui qui prépare une remise si l'article est déjà dans l'armoire.
 * Le point sensible n'est pas la lecture du stock — c'est sa répartition entre les lignes
 * qui se le disputent.
 */
final class DotationProvenanceTest extends StockIntegrationTestCase
{
    private function provenance(): DotationProvenanceResolver
    {
        return $this->service(DotationProvenanceResolver::class);
    }

    public function testEnStockQuandLArmoireCouvreLaLigne(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoin = $this->makeBesoin($season, $veste, 'L');
        $this->makeMovement($veste, 3, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        self::assertSame(
            DotationProvenance::EN_STOCK,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    public function testACommanderQuandNiStockNiCommande(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoin = $this->makeBesoin($season, $veste, 'L');
        $this->em->flush();

        self::assertSame(
            DotationProvenance::A_COMMANDER,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    public function testCommandeQuandUnColisCouvreLaLigne(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoin = $this->makeBesoin($season, $veste, 'L');
        $this->makeCommandeEnAttente($season, $veste, 'L', 2);
        $this->em->flush();

        self::assertSame(
            DotationProvenance::COMMANDE,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    /**
     * Le cœur du sujet : deux personnes attendent la même taille, il n'en reste qu'une.
     * Une lecture ligne à ligne annoncerait « Stock » deux fois et enverrait le second
     * dirigeant chercher un carton vide.
     */
    public function testLeStockNEstAnnonceQuUneFois(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $premier = $this->makeBesoin($season, $veste, 'L');
        $second = $this->makeBesoin($season, $veste, 'L');
        $this->makeMovement($veste, 1, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $provenances = $this->provenance()->parBesoin($season);

        self::assertSame(DotationProvenance::EN_STOCK, $provenances[$premier->getId()], 'Premier inscrit, premier servi.');
        self::assertSame(DotationProvenance::A_COMMANDER, $provenances[$second->getId()]);
    }

    /** Une ligne de 2 avec une seule pièce en armoire n'est pas « Stock » : le compte n'y est pas. */
    public function testUneLigneNEstPasServieAMoitie(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoin = $this->makeBesoin($season, $veste, 'L', 2);
        $this->makeMovement($veste, 1, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        self::assertSame(
            DotationProvenance::A_COMMANDER,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    public function testLeStockDUneAutreTailleNeComptePas(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoin = $this->makeBesoin($season, $veste, 'M');
        $this->makeMovement($veste, 5, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        self::assertSame(
            DotationProvenance::A_COMMANDER,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    /** Une ligne déjà remise a consommé son stock : elle n'a plus de provenance à annoncer. */
    public function testLesLignesRemisesSontHorsSujet(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $donne = $this->makeBesoin($season, $veste, 'L', 1, DotationBesoinStatut::DONNE);
        $this->makeMovement($veste, 3, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        self::assertArrayNotHasKey($donne->getId(), $this->provenance()->parBesoin($season));
    }

    /**
     * La provenance se lit sur l'article **servi**. Une ligne reprise par un stock en cours
     * d'écoulement doit s'annoncer « Stock » alors que l'article du kit, lui, est à zéro —
     * c'est précisément l'information qui manquait au préparateur.
     */
    public function testLaProvenanceSuitLArticleDEcoulement(): void
    {
        $season = $this->makeSeason();
        $erima = $this->makeItem('Chaussettes Erima', StockItemVetementType::CHAUSSURES);
        $nike = $this->makeItem('Chaussettes Nike', StockItemVetementType::CHAUSSURES);
        $nike->setRemplaceArticle($erima);

        $besoin = $this->makeBesoin($season, $erima, '34');
        $besoin->setArticleEcoulement($nike);
        $this->makeMovement($nike, 9, StockMovementType::ENTREE, '34');
        $this->em->flush();

        self::assertSame(
            DotationProvenance::EN_STOCK,
            $this->provenance()->parBesoin($season)[$besoin->getId()],
        );
    }

    /** L'ordre annoncé est celui de la création des besoins, quel que soit celui de lecture. */
    public function testOrdreDeterministe(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $besoins = [
            $this->makeBesoin($season, $veste, 'L'),
            $this->makeBesoin($season, $veste, 'L'),
            $this->makeBesoin($season, $veste, 'L'),
        ];
        $this->makeMovement($veste, 2, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $lu = static fn (array $p, DotationBesoin $b): DotationProvenance => $p[$b->getId()];

        $premier = $this->provenance()->parBesoin($season);
        $second = $this->provenance()->parBesoin($season);

        foreach ($besoins as $besoin) {
            self::assertSame($lu($premier, $besoin), $lu($second, $besoin), 'Deux lectures consécutives disent la même chose.');
        }

        self::assertSame(DotationProvenance::EN_STOCK, $lu($premier, $besoins[0]));
        self::assertSame(DotationProvenance::EN_STOCK, $lu($premier, $besoins[1]));
        self::assertSame(DotationProvenance::A_COMMANDER, $lu($premier, $besoins[2]));
    }
}

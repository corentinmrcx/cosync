<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Stock\AchatService;

/**
 * Le défaut que les grilles corrigent : sans traduction, un licencié en pointure 44 sortait du
 * stock une paire de chaussettes « 44 » alors que le club n'a jamais rangé que du « 43-46 ».
 * Le compteur du 44 partait en négatif et celui du 43-46 ne bougeait pas.
 */
final class DotationGrilleTailleTest extends StockIntegrationTestCase
{
    public function testLaTailleDeclareeEstTraduiteDansLeVocabulaireDuFournisseur(): void
    {
        $besoin = $this->besoinDeChaussettes('44');

        self::assertSame('43-46', $besoin->getTaille(), 'Le besoin doit porter la déclinaison du carton, pas la pointure déclarée.');
    }

    public function testLaRemiseSortLeStockSousLaDeclinaisonDuFournisseur(): void
    {
        $besoin = $this->besoinDeChaussettes('44');
        $item = $besoin->getStockItem();

        $this->makeMovement($item, 10, StockMovementType::ENTREE, '43-46');
        $this->em->flush();

        self::getContainer()->get(DotationRemiseService::class)->marquerRemis($besoin, null);
        $this->em->flush();

        $mouvements = self::getContainer()->get(StockMovementRepository::class);

        self::assertSame(9, $mouvements->getCurrentStockByTaille($item, '43-46'), 'La sortie doit décrémenter la déclinaison réellement en stock.');
        self::assertSame(0, $mouvements->getCurrentStockByTaille($item, '44'), 'Aucune ligne ne doit apparaître sous la pointure déclarée.');
        self::assertSame('43-46', $besoin->getMouvementSortie()?->getTaille());
    }

    public function testUnArticleSansGrilleGardeLaTailleDeclaree(): void
    {
        $season = $this->makeSeason();
        $categorie = $this->makeCategory();
        $licencie = $this->makeLicencie($season, $categorie, tailleHaut: 'XL');

        $maillot = $this->makeItem('Maillot', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $maillot);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        self::getContainer()->get(DotationBesoinSynchronizer::class)->recomputeForLicencie($licencie);

        self::assertSame('XL', $this->besoinUnique()->getTaille(), 'Sans grille, il n\'y a rien à traduire.');
    }

    /**
     * Une grille ne traduit que ce qu'elle mentionne. Le 48, qu'aucune plage ne couvre, sort
     * tel quel — comme si l'article n'avait pas de grille du tout, ce qui est exactement ce
     * que « la grille ne dit rien de cette taille » veut dire.
     */
    public function testUnePointureQueLaGrilleNeMentionnePasSortTelleQuelle(): void
    {
        $besoin = $this->besoinDeChaussettes('48');

        self::assertSame('48', $besoin->getTaille());
        self::assertFalse($besoin->isTailleManuelle(), 'Rien n\'a été fixé à la main : ajouter la plage remplacera la valeur.');
    }

    public function testAnnulerLaRemiseRestitueLaDeclinaisonDuFournisseur(): void
    {
        $besoin = $this->besoinDeChaussettes('44');
        $item = $besoin->getStockItem();
        $this->makeMovement($item, 10, StockMovementType::ENTREE, '43-46');
        $this->em->flush();

        $remise = self::getContainer()->get(DotationRemiseService::class);
        $remise->marquerRemis($besoin, null);
        $this->em->flush();
        $remise->annulerRemise($besoin);
        $this->em->flush();

        $mouvements = self::getContainer()->get(StockMovementRepository::class);

        self::assertSame(10, $mouvements->getCurrentStockByTaille($item, '43-46'), 'Le stock revient là d\'où il est sorti.');
        self::assertNull($besoin->getMouvementSortie());
    }

    public function testCorrigerLaTailleApresRemiseDeplaceLeStockDUnePlageALAutre(): void
    {
        // L'admin constate au local que la plage servie ne va pas : le stock doit suivre.
        $besoin = $this->besoinDeChaussettes('44');
        $item = $besoin->getStockItem();
        $this->makeMovement($item, 5, StockMovementType::ENTREE, '43-46');
        $this->makeMovement($item, 5, StockMovementType::ENTREE, '39-42');
        $this->em->flush();

        $remise = self::getContainer()->get(DotationRemiseService::class);
        $remise->marquerRemis($besoin, null);
        $this->em->flush();
        $remise->changerTaille($besoin, '39-42');
        $this->em->flush();

        $mouvements = self::getContainer()->get(StockMovementRepository::class);

        self::assertSame(5, $mouvements->getCurrentStockByTaille($item, '43-46'), 'La plage rendue est recréditée.');
        self::assertSame(4, $mouvements->getCurrentStockByTaille($item, '39-42'), 'La plage réellement servie est débitée.');
        self::assertTrue($besoin->isTailleManuelle());
    }

    public function testUneTailleCorrigeeAlaMainResisteAuRecalculPuisRepasseEnAutomatique(): void
    {
        $besoin = $this->besoinDeChaussettes('44');
        $licencie = $besoin->getLicencie();
        self::assertNotNull($licencie);

        $remise = self::getContainer()->get(DotationRemiseService::class);
        $synchronizer = self::getContainer()->get(DotationBesoinSynchronizer::class);

        $remise->changerTaille($besoin, '39-42');
        $this->em->flush();
        $synchronizer->recomputeForLicencie($licencie);
        self::assertSame('39-42', $besoin->getTaille(), 'Une correction admin prime sur la traduction.');

        // Une taille vidée rend la main à la grille.
        $remise->changerTaille($besoin, '');
        $this->em->flush();
        $synchronizer->syncTaillesFromDossiers($licencie->getSeason());

        self::assertSame('43-46', $besoin->getTaille());
        self::assertFalse($besoin->isTailleManuelle());
    }

    /**
     * Une pointure hors des plages saisies se commande sous son propre nom. C'est ce que
     * l'admin voit sur le bon de commande — « Chaussettes · 48 » — et c'est de là qu'il sait
     * qu'une plage manque à la grille, sans qu'une ligne sans taille lui reste sur les bras.
     */
    public function testUnBesoinNonTraduitSeCommandeSousSaTailleDeclaree(): void
    {
        $besoin = $this->besoinDeChaussettes('48');
        $item = $besoin->getStockItem();

        $lignes = [];
        foreach (self::getContainer()->get(AchatService::class)->computeACommander($besoin->getSeason()) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                if ($ligne['stockItem']->getId() === $item->getId()) {
                    $lignes[] = $ligne['taille'];
                }
            }
        }

        self::assertSame(['48'], $lignes, 'La taille déclarée, pas une plage devinée ni un trou.');
    }

    /** Un licencié à la pointure donnée, doté de chaussettes vendues en plages. */
    private function besoinDeChaussettes(string $pointure): DotationBesoin
    {
        $season = $this->makeSeason();
        $categorie = $this->makeCategory();
        $licencie = $this->makeLicencie($season, $categorie, pointure: $pointure);

        $grille = $this->makeGrille('Chaussettes Nike', TailleType::POINTURE, [
            '39-42' => ['39', '40', '41', '42'],
            '43-46' => ['43', '44', '45', '46'],
        ]);

        $chaussettes = $this->makeItem('Chaussettes', StockItemVetementType::CHAUSSURES);
        $chaussettes->setGrilleTaille($grille);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $chaussettes);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        self::getContainer()->get(DotationBesoinSynchronizer::class)->recomputeForLicencie($licencie);

        return $this->besoinUnique();
    }

    private function besoinUnique(): DotationBesoin
    {
        $besoins = self::getContainer()->get(DotationBesoinRepository::class)->findAll();
        self::assertCount(1, $besoins);

        return $besoins[0];
    }
}

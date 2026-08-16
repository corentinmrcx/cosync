<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationEcoulementAllocator;
use App\Service\Dotation\DotationEcoulementService;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Stock\AchatService;
use App\Service\Stock\StockItemService;

/**
 * Le défaut que l'écoulement corrige : le club passe de Nike à ERIMA, il reste des chaussettes
 * Nike au local, et le calcul des achats — qui ne déduit que le stock de l'article inscrit au
 * kit — fait racheter de l'ERIMA par-dessus un carton plein.
 */
final class DotationEcoulementTest extends StockIntegrationTestCase
{
    public function testLeStockRestantEstServiEtSeulLeReliquatSeCommande(): void
    {
        $scene = $this->transitionChaussettes(['34', '34']);

        // Une seule paire Nike au local : le premier inscrit la reçoit, le second attend ses ERIMA.
        $this->makeMovement($scene['nike'], 1, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoins = $this->besoins($scene['season']);

        self::assertSame('Chaussettes Nike', $besoins[0]->getArticleServi()->getNom(), 'Le stock restant part avant le neuf.');
        self::assertSame('Chaussettes ERIMA', $besoins[1]->getArticleServi()->getNom(), 'Une fois le stock épuisé, on repasse à l\'article du kit.');
        self::assertTrue($besoins[0]->estServiParEcoulement());

        $lignes = $this->lignesACommander($scene['season']);

        self::assertSame(['Chaussettes ERIMA' => 1], $lignes, 'Une seule paire à commander, et jamais de Nike : un article d\'écoulement ne se rachète pas.');
    }

    public function testSansEcoulementRienNeChange(): void
    {
        $scene = $this->transitionChaussettes(['34', '34']);
        $scene['nike']->setRemplaceArticle(null);
        $this->makeMovement($scene['nike'], 5, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        self::assertSame(['Chaussettes ERIMA' => 2], $this->lignesACommander($scene['season']), 'Un stock qui ne déclare rien reste un stock étranger au kit.');
    }

    public function testUnBesoinNeSeSertPasAMoitie(): void
    {
        $scene = $this->transitionChaussettes(['34'], quantite: 2);
        $this->makeMovement($scene['nike'], 1, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        self::assertFalse($this->besoins($scene['season'])[0]->estServiParEcoulement(), 'Servir une paire sur deux casserait la remise : la ligne reste entière sur l\'article du kit.');
        self::assertSame(['Chaussettes ERIMA' => 2], $this->lignesACommander($scene['season']));
    }

    public function testUneTailleQueLeStockRestantNeCouvrePasNeSubstituePas(): void
    {
        $scene = $this->transitionChaussettes(['34']);
        // L'ancien fournisseur étiquetait par plages, et la sienne s'arrête au-dessus du 34.
        $scene['nike']->setGrilleTaille($this->makeGrille('Plages Nike', TailleType::POINTURE, ['39-42' => ['40', '41']]));
        $this->makeMovement($scene['nike'], 10, StockMovementType::ENTREE, '39-42');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];

        self::assertFalse($besoin->estServiParEcoulement(), 'Une taille non couverte écarte le substitut, elle n\'en fait pas approcher une autre.');
        self::assertSame('34', $besoin->getTaille(), 'La taille reste celle de l\'article du kit.');
    }

    public function testLaTailleSuitLEchelleDeLArticleServi(): void
    {
        $scene = $this->transitionChaussettes(['34']);
        $scene['nike']->setGrilleTaille($this->makeGrille('Plages Nike', TailleType::POINTURE, ['33-36' => ['34', '35']]));
        $this->makeMovement($scene['nike'], 2, StockMovementType::ENTREE, '33-36');
        $this->allouer($scene['season']);

        self::assertSame('33-36', $this->besoins($scene['season'])[0]->getTaille(), 'Le besoin doit parler la déclinaison du carton qu\'on va ouvrir.');
    }

    public function testLaRemiseDecrementeLeStockEcouleEtPasCeluiDuKit(): void
    {
        $scene = $this->transitionChaussettes(['34']);
        $this->makeMovement($scene['nike'], 3, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['erima'], 3, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];
        self::getContainer()->get(DotationRemiseService::class)->marquerRemis($besoin, null);
        $this->em->flush();

        $mouvements = self::getContainer()->get(StockMovementRepository::class);

        self::assertSame(2, $mouvements->getCurrentStockByTaille($scene['nike'], '34'), 'C\'est le carton ouvert qui se vide.');
        self::assertSame(3, $mouvements->getCurrentStockByTaille($scene['erima'], '34'), 'Le stock du kit n\'a pas bougé.');
    }

    public function testEpinglerLArticleDuKitLaisseLeStockRestantAuSuivant(): void
    {
        $scene = $this->transitionChaussettes(['34', '34']);
        $this->makeMovement($scene['nike'], 1, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoins = $this->besoins($scene['season']);
        self::assertSame('Chaussettes Nike', $besoins[0]->getArticleServi()->getNom());

        // L'admin veut du neuf pour le premier : la paire restante doit revenir au second.
        self::getContainer()->get(DotationEcoulementService::class)
            ->fixerArticle($besoins[0], (string) $scene['erima']->getId());

        $besoins = $this->besoins($scene['season']);

        self::assertSame('Chaussettes ERIMA', $besoins[0]->getArticleServi()->getNom());
        self::assertTrue($besoins[0]->isArticleManuel(), 'Un article épinglé ne se fait plus déloger par l\'arbitrage.');
        self::assertSame('Chaussettes Nike', $besoins[1]->getArticleServi()->getNom(), 'La paire libérée profite à quelqu\'un d\'autre.');
    }

    public function testUnEpinglageQueLeStockNeCouvrePlusEstRelache(): void
    {
        $scene = $this->transitionChaussettes(['34']);
        $this->makeMovement($scene['nike'], 1, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];
        self::getContainer()->get(DotationEcoulementService::class)
            ->fixerArticle($besoin, (string) $scene['nike']->getId());
        self::assertTrue($besoin->estServiParEcoulement());

        // La paire part au rebut : la réservation ne peut plus être honorée.
        $this->makeMovement($scene['nike'], 1, StockMovementType::REBUT, '34');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];

        self::assertFalse($besoin->estServiParEcoulement(), 'Le club ne peut pas remettre ce qu\'il n\'a plus : la ligne revient au kit.');
        self::assertFalse($besoin->isArticleManuel());
        self::assertSame(['Chaussettes ERIMA' => 1], $this->lignesACommander($scene['season']), 'Et elle se commande de nouveau.');
    }

    public function testUnArticleDejaRemisNeChangePlusDArticle(): void
    {
        $scene = $this->transitionChaussettes(['34']);
        $this->makeMovement($scene['nike'], 2, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];
        self::getContainer()->get(DotationRemiseService::class)->marquerRemis($besoin, null);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        self::getContainer()->get(DotationEcoulementService::class)
            ->fixerArticle($besoin, (string) $scene['erima']->getId());
    }

    /* ── Déclaration de la règle ── */

    public function testUneChaineDEcoulementEstRefusee(): void
    {
        $scene = $this->transitionChaussettes([]);
        $adidas = $this->makeEquipement('Chaussettes Adidas', StockItemVetementType::CHAUSSURES);
        $this->em->flush();

        $this->expectExceptionMessageMatches('/écoulement/');
        self::getContainer()->get(StockItemService::class)->appliquerEcoulement($adidas, $scene['nike']);
    }

    public function testUnArticleDUnAutreTypeDeVetementEstRefuse(): void
    {
        $scene = $this->transitionChaussettes([]);
        $short = $this->makeEquipement('Short Nike', StockItemVetementType::BAS);
        $this->em->flush();

        // Lire la taille du bas pour servir un pied ferait sortir la mauvaise déclinaison.
        $this->expectExceptionMessageMatches('/type de vêtement/');
        self::getContainer()->get(StockItemService::class)->appliquerEcoulement($short, $scene['erima']);
    }

    public function testUnArticleNeSEcoulePasASaProprePlace(): void
    {
        $scene = $this->transitionChaussettes([]);

        $this->expectExceptionMessageMatches('/propre place/');
        self::getContainer()->get(StockItemService::class)->appliquerEcoulement($scene['erima'], $scene['erima']);
    }

    /* ── Décor ── */

    /**
     * Le club a changé de fournisseur : le kit prévoit des ERIMA, il reste des Nike au local.
     *
     * @param list<string> $pointures une par licencié à doter
     *
     * @return array{season: Season, erima: StockItem, nike: StockItem}
     */
    private function transitionChaussettes(array $pointures, int $quantite = 1): array
    {
        $season = $this->makeSeason();
        $categorie = $this->makeCategory();

        $erima = $this->makeEquipement('Chaussettes ERIMA', StockItemVetementType::CHAUSSURES);
        $nike = $this->makeEquipement('Chaussettes Nike', StockItemVetementType::CHAUSSURES);
        $nike->setRemplaceArticle($erima);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $erima, $quantite);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        $synchronizer = self::getContainer()->get(DotationBesoinSynchronizer::class);
        foreach ($pointures as $pointure) {
            $synchronizer->recomputeForLicencie($this->licencieEnPointure($season, $categorie, $pointure));
        }

        return ['season' => $season, 'erima' => $erima, 'nike' => $nike];
    }

    private function licencieEnPointure(Season $season, \App\Entity\Category $categorie, string $pointure): Licencie
    {
        $this->taille($pointure, TailleType::POINTURE, proposee: true);

        return $this->makeLicencie($season, $categorie, pointure: $pointure);
    }

    private function makeEquipement(string $nom, StockItemVetementType $type): StockItem
    {
        return $this->makeItem($nom, $type)->setKind(StockItemKind::EQUIPEMENT);
    }

    private function allouer(Season $season): void
    {
        $this->em->flush();
        self::getContainer()->get(DotationEcoulementAllocator::class)->allouer($season);
    }

    /**
     * Besoins de la saison dans l'ordre où l'arbitrage les sert : par création.
     *
     * @return list<DotationBesoin>
     */
    private function besoins(Season $season): array
    {
        $besoins = self::getContainer()->get(DotationBesoinRepository::class)->findBySeason($season);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }

    /** @return array<string, int> { nom d'article: quantité à commander } */
    private function lignesACommander(Season $season): array
    {
        $out = [];

        foreach (self::getContainer()->get(AchatService::class)->computeACommander($season) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                $nom = $ligne['stockItem']->getNom();
                $out[$nom] = ($out[$nom] ?? 0) + $ligne['aCommander'];
            }
        }

        return $out;
    }
}

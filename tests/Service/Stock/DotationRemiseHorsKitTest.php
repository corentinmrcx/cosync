<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationEcoulementAllocator;
use App\Service\Dotation\DotationPreparationService;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Stock\AchatService;
use App\Service\Stock\StockItemService;

/**
 * Le défaut que la remise hors kit corrige : le kit prévoit des chaussettes montantes, le
 * président en a donné des coupées parce qu'il y en avait dans l'armoire. Le joueur est servi,
 * mais la ligne restait « à donner » — et le club recommandait des montantes pour quelqu'un qui
 * avait déjà ses chaussettes.
 *
 * Ce qu'on refusait de faire à la place, et qui explique la forme du geste : déclarer les
 * coupées en écoulement des montantes. C'est une **règle**, l'arbitrage l'applique à toute la
 * saison, et le club se serait mis à distribuer des chaussettes coupées à tout le monde. Ici
 * rien n'est déclaré : on constate, sur une ligne, ce qui est sorti de l'armoire.
 */
final class DotationRemiseHorsKitTest extends StockIntegrationTestCase
{
    public function testLaLigneEstServieEtNeSeCommandePlus(): void
    {
        $scene = $this->chaussettes(['34']);
        $besoin = $this->besoins($scene['season'])[0];

        self::assertSame(['Chaussettes montantes' => 1], $this->lignesACommander($scene['season']));

        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);

        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut());
        self::assertTrue($besoin->estRemisHorsKit());
        self::assertSame(
            [],
            $this->lignesACommander($scene['season']),
            'On ne recommande pas ce qui est déjà aux pieds du joueur.',
        );
    }

    public function testCEstLeCartonOuvertQuiDecremente(): void
    {
        $scene = $this->chaussettes(['34']);
        $this->makeMovement($scene['montantes'], 3, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 2, StockMovementType::ENTREE, '34');

        $this->remise()->remettreAutreArticle($this->besoins($scene['season'])[0], $scene['coupees'], null);

        self::assertSame(1, $this->stock($scene['coupees'], '34'), 'La paire donnée sort du stock…');
        self::assertSame(3, $this->stock($scene['montantes'], '34'), '…et celle du kit, qu\'on n\'a pas touchée, y reste.');
    }

    /**
     * Le cœur du geste : il ne laisse **aucune** trace réutilisable. Un second licencié qui
     * attend les mêmes chaussettes doit se voir commander des montantes, pas hériter des
     * coupées — sans quoi l'exception d'un soir serait devenue la règle du club.
     */
    public function testAucuneRegleNEnDecoulePourLesAutres(): void
    {
        $scene = $this->chaussettes(['34', '34']);
        $this->makeMovement($scene['coupees'], 5, StockMovementType::ENTREE, '34');

        [$premier, $second] = $this->besoins($scene['season']);
        $this->remise()->remettreAutreArticle($premier, $scene['coupees'], null);
        $this->allouer($scene['season']);

        self::assertSame('Chaussettes montantes', $second->getArticleServi()->getNom(), 'Le second reste sur le kit.');
        self::assertFalse($second->estServiParEcoulement(), 'Rien n\'a été déclaré : l\'arbitrage n\'a rien à proposer.');
        self::assertSame(
            ['Chaussettes montantes' => 1],
            $this->lignesACommander($scene['season']),
            'Les quatre paires coupées restantes ne couvrent pas un besoin de montantes.',
        );
    }

    public function testLaSortieEstRangeeSousUneDeclinaisonQueLArticleVend(): void
    {
        $scene = $this->chaussettes(['34']);
        // Les coupées viennent d'un autre fournisseur : il étiquette ses cartons « 34-38 ».
        $scene['coupees']->setGrilleTaille($this->makeGrille('Coupées', TailleType::POINTURE, ['34-38' => ['34', '35', '36', '37', '38']]));
        $this->makeMovement($scene['coupees'], 2, StockMovementType::ENTREE, '34-38');

        $besoin = $this->besoins($scene['season'])[0];
        self::assertSame('34', $besoin->getTaille(), 'Le kit, lui, s\'étiquette à la pointure.');

        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);

        self::assertSame('34-38', $besoin->getTaille(), 'La taille suit le carton ouvert.');
        self::assertSame(1, $this->stock($scene['coupees'], '34-38'), 'Sortie rangée sous l\'étiquette du fournisseur…');
        self::assertSame(0, $this->stock($scene['coupees'], '34'), '…et non sous une déclinaison qu\'il ne vend pas.');
    }

    public function testAnnulerLaRemiseRendLaLigneAuKit(): void
    {
        $scene = $this->chaussettes(['34']);
        $this->makeMovement($scene['coupees'], 2, StockMovementType::ENTREE, '34');
        $besoin = $this->besoins($scene['season'])[0];

        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);
        $this->remise()->annulerRemise($besoin);

        self::assertFalse($besoin->estRemisHorsKit(), 'L\'exception ne survit pas à la remise qu\'elle décrivait.');
        self::assertSame('Chaussettes montantes', $besoin->getArticleServi()->getNom());
        self::assertSame(2, $this->stock($scene['coupees'], '34'), 'Les coupées reviennent en stock.');
        self::assertSame(['Chaussettes montantes' => 1], $this->lignesACommander($scene['season']), 'La ligne repasse aux achats.');
    }

    public function testUneLigneDejaRemiseRefuse(): void
    {
        $scene = $this->chaussettes(['34']);
        $besoin = $this->besoins($scene['season'])[0];
        $this->remise()->marquerRemis($besoin, null);

        $this->expectExceptionMessageMatches('/Annulez d\'abord la remise/');
        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);
    }

    /** Reprendre l'article du kit dans le sélecteur, c'est une remise ordinaire, pas une exception. */
    public function testReprendreLArticleDuKitNeMarqueRienDeParticulier(): void
    {
        $scene = $this->chaussettes(['34']);
        $besoin = $this->besoins($scene['season'])[0];

        $this->remise()->remettreAutreArticle($besoin, $scene['montantes'], null);

        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut());
        self::assertFalse($besoin->estRemisHorsKit(), 'La ligne n\'a rien à signaler : c\'est bien le kit qui est parti.');
    }

    /**
     * La colonne est en `SET NULL` : sans garde, supprimer l'article du catalogue effacerait en
     * silence la trace de ce qui a été donné.
     *
     * Le mouvement de sortie protégeait déjà l'article — mais lui se supprime depuis l'écran
     * du stock, et la ligne de dotation, elle, continue de désigner l'article. C'est cet
     * état-là qu'on met à l'épreuve : plus de mouvement, et une remise qui tient toujours.
     */
    public function testLArticleRemisNeSeSupprimePlusDuCatalogue(): void
    {
        $scene = $this->chaussettes(['34']);
        $besoin = $this->besoins($scene['season'])[0];
        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);

        $this->em->remove($besoin->getMouvementSortie());
        $this->em->flush();

        $analyse = $this->service(StockItemService::class)->analyserSuppression($scene['coupees']);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('remis à la place', (string) $analyse->motifArchivage);
    }

    /* ── Ce que le stock en voit ── */

    /**
     * Corriger la taille d'une ligne déjà remise rejoue le mouvement. Il doit être rejoué sur
     * l'article **donné** : rejoué sur celui du kit, il rendrait une paire de montantes que
     * personne n'a jamais sortie et en garderait une de coupées en moins.
     */
    public function testCorrigerLaTailleApresCoupRejoueLeMouvementSurLArticleDonne(): void
    {
        $scene = $this->chaussettes(['34']);
        $this->taille('38', TailleType::POINTURE, proposee: true);
        $this->makeMovement($scene['montantes'], 4, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 3, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 3, StockMovementType::ENTREE, '38');

        $besoin = $this->besoins($scene['season'])[0];
        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);
        $this->remise()->changerTaille($besoin, '38', null);

        self::assertSame(3, $this->stock($scene['coupees'], '34'), 'La paire sortie en 34 est restituée…');
        self::assertSame(2, $this->stock($scene['coupees'], '38'), '…et c\'est le 38 qui part.');
        self::assertSame(4, $this->stock($scene['montantes'], '34'), 'Le kit n\'a toujours rien vu partir.');
    }

    /**
     * Le sac était fait avec l'article du kit, c'est autre chose qui est parti avec la personne.
     * La préparation n'ayant jamais rien sorti, le stock du kit ne doit pas bouger d'un iota —
     * et l'unité qu'elle réservait retourne à l'arbitrage pour quelqu'un d'autre.
     */
    public function testUnSacPrepareNAPasSortiDeStockEtSonUniteRevientAuxAutres(): void
    {
        $scene = $this->chaussettes(['34']);
        $this->makeMovement($scene['montantes'], 2, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 2, StockMovementType::ENTREE, '34');

        $besoin = $this->besoins($scene['season'])[0];
        $this->service(DotationPreparationService::class)->preparer($besoin);
        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);

        self::assertSame(2, $this->stock($scene['montantes'], '34'), 'Préparer n\'avait rien sorti, remettre autre chose non plus.');
        self::assertSame(1, $this->stock($scene['coupees'], '34'));
        self::assertSame([], $this->lignesACommander($scene['season']), 'La ligne est servie.');
    }

    /**
     * La ligne était couverte par un écoulement — on allait lui servir du Nike — et c'est
     * encore autre chose qui est parti. C'est le carton réellement ouvert qui décrémente, et la
     * paire Nike promise retourne au pool.
     */
    public function testUneLigneCouverteParUnEcoulementSortLArticleReellementDonne(): void
    {
        $scene = $this->chaussettes(['34']);
        $nike = $this->makeEquipement('Chaussettes Nike')->setRemplaceArticle($scene['montantes']);
        $this->makeMovement($nike, 1, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 2, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoin = $this->besoins($scene['season'])[0];
        self::assertTrue($besoin->estServiParEcoulement(), 'L\'arbitrage lui promettait la paire Nike.');

        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);

        self::assertSame(1, $this->stock($nike, '34'), 'La paire Nike n\'est pas sortie : on ne l\'a pas donnée.');
        self::assertSame(1, $this->stock($scene['coupees'], '34'), 'C\'est le carton ouvert qui décrémente.');
        self::assertSame('Chaussettes coupées', $besoin->getArticleServi()->getNom());
    }

    /**
     * Le test de conservation : une remise hors kit annulée puis rejouée normalement doit
     * laisser le stock exactement comme si le détour n'avait pas eu lieu. C'est ce qui garantit
     * qu'une erreur de saisie se rattrape sans laisser de trou dans l'armoire.
     */
    public function testUnAllerRetourNeLaissePasDeTrouDansLArmoire(): void
    {
        $scene = $this->chaussettes(['34']);
        $this->makeMovement($scene['montantes'], 5, StockMovementType::ENTREE, '34');
        $this->makeMovement($scene['coupees'], 5, StockMovementType::ENTREE, '34');

        $besoin = $this->besoins($scene['season'])[0];
        $this->remise()->remettreAutreArticle($besoin, $scene['coupees'], null);
        $this->remise()->annulerRemise($besoin);
        $this->remise()->marquerRemis($besoin, null);

        self::assertSame(4, $this->stock($scene['montantes'], '34'), 'Seule la paire réellement donnée manque…');
        self::assertSame(5, $this->stock($scene['coupees'], '34'), '…et le détour n\'a rien coûté aux coupées.');
    }

    /**
     * Un besoin de deux paires sort deux paires du carton ouvert — pas une, pas celles du kit.
     */
    public function testLaQuantiteDuBesoinSortEntierementDuCartonOuvert(): void
    {
        $scene = $this->chaussettes(['34'], quantite: 2);
        $this->makeMovement($scene['coupees'], 5, StockMovementType::ENTREE, '34');

        $this->remise()->remettreAutreArticle($this->besoins($scene['season'])[0], $scene['coupees'], null);

        self::assertSame(3, $this->stock($scene['coupees'], '34'));
    }

    /* ── Décor ── */

    /**
     * Le kit prévoit des chaussettes montantes ; des coupées dorment dans l'armoire sans
     * qu'aucune règle ne les relie — c'est tout l'enjeu.
     *
     * @param list<string> $pointures une par licencié à doter
     * @param int          $quantite  paires prévues par le kit
     *
     * @return array{season: Season, montantes: StockItem, coupees: StockItem}
     */
    private function chaussettes(array $pointures, int $quantite = 1): array
    {
        $season = $this->makeSeason();
        $categorie = $this->makeCategory();

        $montantes = $this->makeEquipement('Chaussettes montantes');
        $coupees = $this->makeEquipement('Chaussettes coupées');

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $montantes, $quantite);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        foreach ($pointures as $pointure) {
            $this->taille($pointure, TailleType::POINTURE, proposee: true);
            $this->service(DotationBesoinSynchronizer::class)
                ->recomputeForLicencie($this->makeLicencie($season, $categorie, pointure: $pointure));
        }

        return ['season' => $season, 'montantes' => $montantes, 'coupees' => $coupees];
    }

    private function makeEquipement(string $nom): StockItem
    {
        return $this->makeItem($nom, StockItemVetementType::CHAUSSURES)->setKind(StockItemKind::EQUIPEMENT);
    }

    private function remise(): DotationRemiseService
    {
        $this->em->flush();

        return $this->service(DotationRemiseService::class);
    }

    private function allouer(Season $season): void
    {
        $this->em->flush();
        $this->service(DotationEcoulementAllocator::class)->allouer($season);
    }

    private function stock(StockItem $item, string $taille): int
    {
        return $this->service(StockMovementRepository::class)->getCurrentStockByTaille($item, $taille);
    }

    /** @return list<DotationBesoin> */
    private function besoins(Season $season): array
    {
        $besoins = $this->service(DotationBesoinRepository::class)->findBySeason($season);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }

    /** @return array<string, int> { nom d'article: quantité à commander } */
    private function lignesACommander(Season $season): array
    {
        $out = [];

        foreach ($this->service(AchatService::class)->computeACommander($season) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                $nom = $ligne['stockItem']->getNom();
                $out[$nom] = ($out[$nom] ?? 0) + $ligne['aCommander'];
            }
        }

        return $out;
    }
}

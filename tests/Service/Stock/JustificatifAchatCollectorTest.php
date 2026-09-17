<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\Justificatif\JustificatifAchat;
use App\DTO\Justificatif\JustificatifArticle;
use App\Entity\Season;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationEcoulementAllocator;
use App\Service\Dotation\DotationPreparationService;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Stock\AchatService;
use App\Service\Stock\JustificatifAchatCollector;

/**
 * Le justificatif est une pièce comptable : chaque ligne doit se vérifier de tête — **il en
 * faut N, on en a S, on en commande N - S** — et ne jamais dire autre chose que le bon de
 * commande de la même journée.
 */
final class JustificatifAchatCollectorTest extends StockIntegrationTestCase
{
    public function testChaqueLigneSeVerifieDeTete(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $this->makeFournisseur('Sport2000'));

        $this->makeBesoin($season, $veste, 'L', 10);
        $this->makeMovement($veste, 3, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $article = $this->article($this->collecter($season), 'Veste');

        self::assertSame(10, $article->ilEnFaut);
        self::assertSame(3, $article->onEnA);
        self::assertSame(7, $article->aCommander, '10 - 3 = 7.');
    }

    /**
     * L'article qu'on n'achète pas reste au document : c'est le meilleur argument dont le
     * club dispose. « Il en faut 8, on en a 8, on n'achète rien » prouve que l'armoire a été
     * regardée — le taire laisserait croire le contraire.
     */
    public function testUnArticleEntierementCouvertResteAuDocument(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 8);
        $this->makeMovement($veste, 8, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $article = $this->article($this->collecter($season), 'Veste');

        self::assertSame(8, $article->ilEnFaut);
        self::assertSame(8, $article->onEnA);
        self::assertSame(0, $article->aCommander);
        self::assertTrue($article->couvert());
    }

    /**
     * L'objection que le document doit devancer, mot pour mot : « vous en commandez alors
     * qu'il y en a en stock ». Cinq paires nécessaires, trois en armoire, trois sacs déjà
     * faits : un sac préparé n'a pas quitté l'armoire, donc le club les a — et il n'en
     * commande que deux.
     */
    public function testUnSacPrepareCompteDansCeQuOnA(): void
    {
        $doc = $this->scenePreparation(prepares: 3, remis: 0, besoins: 5, stock: 3);
        $article = $this->article($doc, 'Veste');

        self::assertSame(5, $article->ilEnFaut, 'Il en faut cinq sur la saison.');
        self::assertSame(3, $article->onEnA, 'Les trois sacs faits sont toujours au club.');
        self::assertSame(2, $article->aCommander, '5 - 3 = 2.');
    }

    /** Ce qui est remis est sorti du stock : il ne se rachète pas, et ne se recompte pas. */
    public function testUnArticleRemisNePesePlusSurLaCommande(): void
    {
        $doc = $this->scenePreparation(prepares: 0, remis: 4);
        $article = $this->article($doc, 'Veste');

        self::assertSame(6, $article->ilEnFaut, 'Quatre vestes sont parties : il en reste six à remettre.');
        self::assertSame(0, $article->onEnA, 'Leur sortie de stock est faite.');
        self::assertSame(6, $article->aCommander);
        self::assertSame(4, $doc->dejaRemis);
    }

    /**
     * Le test qui garde les milliers d'euros.
     *
     * Trois états du même besoin de 10 vestes avec 4 en armoire : rien de préparé, quatre sacs
     * faits, quatre vestes remises. Les trois **doivent** commander 6 — le calcul ne dépend pas
     * d'où en est la distribution, seulement de ce qui manque.
     *
     * Écarter les sacs préparés du besoin sans écarter leur contenu du stock ferait tomber la
     * commande à 2 : les 4 vestes de l'armoire passeraient pour disponibles alors qu'elles
     * dorment déjà dans des sacs, et quatre licenciés repartiraient les mains vides.
     */
    public function testLePrepareEtLeRemisDonnentLaMemeCommande(): void
    {
        self::assertSame(6, $this->scenePreparation(prepares: 0, remis: 0)->aCommander, 'Rien de distribué : 10 - 4 = 6.');
        self::assertSame(6, $this->scenePreparation(prepares: 4, remis: 0)->aCommander, 'Quatre sacs faits : le club les a encore.');
        self::assertSame(6, $this->scenePreparation(prepares: 0, remis: 4)->aCommander, 'Quatre vestes parties : besoin 6, stock 0.');
    }

    /**
     * Le cas qui a motivé le document. Le kit promet 2 paires, l'ancien stock Nike en couvre
     * une : la ligne doit annoncer qu'il en faut 2, pas 1. Un lecteur qui multiplie l'effectif
     * par la ligne de kit trouve 2 — lui en montrer 1 lui ferait chercher l'erreur là où il
     * n'y en a pas.
     */
    public function testLeBesoinAnnonceEstCeluiDuKitAncienStockCompris(): void
    {
        $article = $this->article($this->transitionChaussettes(), 'Chaussettes ERIMA');

        self::assertSame(2, $article->ilEnFaut, 'Le kit promet 2 paires, et c\'est ce qu\'on doit lire.');
        self::assertSame(1, $article->onEnA, 'Une paire Nike fait l\'affaire.');
        self::assertSame(1, $article->aCommander, '2 - 1 = 1.');
    }

    /** L'ancienne référence ne se devine pas : elle s'écrit, sinon le « on en a 1 » surprend. */
    public function testLAncienneReferenceEstDiteEnNote(): void
    {
        $article = $this->article($this->transitionChaussettes(), 'Chaussettes ERIMA');

        self::assertCount(1, $article->notes);
        self::assertStringContainsString('Chaussettes Nike', $article->notes[0]);
    }

    /** Un article qu'on écoule ne se rachète jamais : il n'a rien à faire au devis. */
    public function testUneAncienneReferenceNeFigurePasALaCommande(): void
    {
        foreach ($this->transitionChaussettes()->fournisseurs as $fournisseur) {
            foreach ($fournisseur->articles as $article) {
                self::assertNotSame('Chaussettes Nike', $article->article->getNom());
            }
        }
    }

    /**
     * Le garde-fou du document : le justificatif consomme le calcul d'achat, il ne le refait
     * pas. Si ce test tombe, c'est que les deux ont commencé à diverger — et un justificatif
     * qui contredit son bon de commande ne justifie plus rien.
     */
    public function testLeTotalNeDivergeJamaisDuBonDeCommande(): void
    {
        $season = $this->makeSeason('2031-2032');
        $this->scenePourSaison($season);

        $doc = $this->service(JustificatifAchatCollector::class)->collecter($season);
        $attendu = $this->service(AchatService::class)->compterACommander($season);

        self::assertSame($attendu, $doc->aCommander);
        self::assertSame(
            $attendu,
            array_sum(array_map(static fn ($f): int => $f->total, $doc->fournisseurs)),
            'Le total du document est la somme de ses fournisseurs.',
        );
    }

    public function testRienACommanderQuandLeStockCouvreToutLaSaison(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 2);
        $this->makeMovement($veste, 5, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $doc = $this->collecter($season);

        self::assertTrue($doc->riendACommander());
        self::assertSame(0, $doc->aCommander);
        self::assertSame(2, $doc->onEnA(), 'Tout le besoin est couvert par l\'armoire.');
    }

    /**
     * La promesse du document : ce qu'on achète part chez un licencié.
     *
     * Trois vestes en trop dans l'armoire ne se taisent pas — c'est ce stock-là qui a fait
     * perdre la confiance, et le document doit l'avouer plutôt que de le laisser découvrir.
     */
    public function testLeStockQuiNeTrouvePasPreneurEstAvoue(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 2);
        $this->makeMovement($veste, 5, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $doc = $this->collecter($season);

        self::assertSame(3, $doc->resteApresDistribution, '5 en armoire, 2 servies.');
        self::assertFalse($doc->toutSeraDistribue());
    }

    public function testToutEstDistribueQuandLArmoireSeVide(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 5);
        $this->makeMovement($veste, 2, StockMovementType::ENTREE, 'L');
        $this->em->flush();

        $doc = $this->collecter($season);

        self::assertSame(0, $doc->resteApresDistribution);
        self::assertTrue($doc->toutSeraDistribue(), 'Les 2 vestes partent, les 3 commandées aussi.');
    }

    /**
     * Le détail par taille ne sort que s'il apprend quelque chose : sur un article à
     * déclinaison unique, il répéterait les nombres de la ligne au-dessus.
     */
    public function testLeDetailParTailleNeSortQueSiLArticleEnAPlusieurs(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 3);
        $this->em->flush();
        self::assertSame([], $this->article($this->collecter($season), 'Veste')->tailles);

        $this->makeBesoin($season, $veste, 'M', 2);
        $this->em->flush();
        self::assertCount(2, $this->article($this->collecter($season), 'Veste')->tailles);
    }

    /**
     * Deux options d'un même groupe de choix ne promettent qu'un article par personne. Mises
     * bout à bout comme deux lignes ordinaires, elles s'additionnaient à la lecture et
     * faisaient paraître la commande deux fois trop petite.
     */
    public function testLesOptionsDUnChoixTiennentSurUneSeuleEntree(): void
    {
        $season = $this->makeSeason();
        $categorie = $this->makeCategory();
        $modele = $this->makeModele($season);

        $this->addLigne($modele, $this->makeItem('Sac de sport', StockItemVetementType::HAUT), 1, groupeChoix: 'sac');
        $this->addLigne($modele, $this->makeItem('Sac à dos', StockItemVetementType::HAUT), 1, groupeChoix: 'sac');
        $this->addLigne($modele, $this->makeItem('Veste', StockItemVetementType::HAUT), 1);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        $kit = $this->collecter($season)->kits[0];

        self::assertCount(2, $kit->entrees, 'Le choix compte pour une entrée, la veste pour l\'autre.');
        self::assertTrue($kit->entrees[0]->auChoix());
        self::assertCount(2, $kit->entrees[0]->options);
        self::assertFalse($kit->entrees[1]->auChoix());
    }

    // ── Scènes ──

    /** Le club passe de Nike à ERIMA : deux paires promises, une seule Nike restante. */
    private function transitionChaussettes(): JustificatifAchat
    {
        $season = $this->makeSeason();
        $this->scenePourSaison($season);

        return $this->collecter($season);
    }

    private function scenePourSaison(Season $season): void
    {
        $categorie = $this->makeCategory();
        $f = $this->makeFournisseur('Sport2000');

        $erima = $this->makeItem('Chaussettes ERIMA', StockItemVetementType::CHAUSSURES, $f)
            ->setKind(StockItemKind::EQUIPEMENT);
        $nike = $this->makeItem('Chaussettes Nike', StockItemVetementType::CHAUSSURES, $f)
            ->setKind(StockItemKind::EQUIPEMENT);
        $nike->setRemplaceArticle($erima);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $erima, 1);
        $this->affecterCategorie($season, $modele, $categorie);
        $this->em->flush();

        // Les besoins passent par le synchronizer : l'allocateur n'arbitre que des lignes
        // rattachées à une personne, c'est d'elle qu'il tient la taille à servir.
        $this->taille('34', TailleType::POINTURE, proposee: true);
        $synchronizer = $this->service(DotationBesoinSynchronizer::class);
        foreach ([1, 2] as $_) {
            $synchronizer->recomputeForLicencie($this->makeLicencie($season, $categorie, pointure: '34'));
        }

        $this->makeMovement($nike, 1, StockMovementType::ENTREE, '34');
        $this->em->flush();
    }

    /** N vestes promises, S en armoire, et une distribution plus ou moins avancée. */
    private function scenePreparation(
        int $prepares,
        int $remis,
        int $besoins = 10,
        int $stock = 4,
    ): JustificatifAchat {
        // Label distinct à chaque appel : les trois états se comparent dans un même test, et
        // une saison porte un libellé unique en base.
        static $n = 0;
        ++$n;
        $season = $this->makeSeason(sprintf('20%02d-20%02d', $n + 40, $n + 41));
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($veste, $stock, StockMovementType::ENTREE, 'L');

        $lignes = [];
        for ($i = 0; $i < $besoins; ++$i) {
            $lignes[] = $this->makeBesoin($season, $veste, 'L');
        }
        $this->em->flush();

        // Les remises d'abord : elles sortent du stock, et un sac préparé par-dessus n'aurait
        // plus rien à réserver. C'est l'ordre du local.
        for ($i = 0; $i < $remis; ++$i) {
            $this->service(DotationRemiseService::class)->marquerRemis($lignes[$i], null);
        }
        for ($i = $remis; $i < $remis + $prepares; ++$i) {
            $this->service(DotationPreparationService::class)->preparer($lignes[$i]);
        }
        $this->em->flush();

        return $this->collecter($season);
    }

    private function collecter(Season $season): JustificatifAchat
    {
        // Même ordre que les contrôleurs : l'arbitrage de l'écoulement passe avant la lecture.
        $this->service(DotationEcoulementAllocator::class)->allouer($season);

        return $this->service(JustificatifAchatCollector::class)->collecter($season);
    }

    private function article(JustificatifAchat $doc, string $nom): JustificatifArticle
    {
        foreach ($doc->fournisseurs as $fournisseur) {
            foreach ($fournisseur->articles as $article) {
                if ($article->article->getNom() === $nom) {
                    return $article;
                }
            }
        }

        self::fail(sprintf('Aucun article « %s » au justificatif.', $nom));
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationAvancementStatut;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationProvenance;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationEcoulementAllocator;
use App\Service\Dotation\DotationEcoulementService;
use App\Service\Dotation\DotationFlocageService;
use App\Service\Dotation\DotationPreparationService;
use App\Service\Dotation\DotationProvenanceResolver;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Dotation\DotationSuiviPresenter;
use App\Service\Stock\AchatService;

/**
 * Le sac est fait mais le licencié n'est pas passé le prendre.
 *
 * Ce que la préparation apporte n'est pas un mouvement — l'armoire contient toujours l'article
 * — mais un **gel** : à partir de là, ni la taille, ni le carton servi, ni l'existence même de
 * la ligne ne se décident plus tout seuls. Sans lui, le suivi finissait par annoncer autre
 * chose que ce que le sac contenait.
 */
final class DotationPreparationTest extends StockIntegrationTestCase
{
    /* ── Ce que la préparation ne fait pas ── */

    public function testPreparerNeSortRienDuStock(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->preparation()->preparer($besoin);

        self::assertSame(DotationBesoinStatut::PREPARE, $besoin->getStatut());
        self::assertNull($besoin->getMouvementSortie(), 'Un sac préparé n\'a pas encore quitté le club.');
        self::assertSame(2, $this->mouvements()->getCurrentStockByTaille($item, 'L'), 'Le stock ne bouge qu\'à la remise.');
    }

    public function testUnBesoinPrepareResteACommander(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $besoin = $this->makeBesoin($season, $veste, 'L', 3);
        $this->em->flush();

        $this->preparation()->preparer($besoin);

        $lignes = $this->service(AchatService::class)->computeACommander($season);
        $ligne = $lignes[0]['lignes'][0] ?? null;

        self::assertNotNull($ligne, 'Écarter les préparés des achats les ferait passer pour servis…');
        self::assertSame(3, $ligne['aCommander'], '…alors que le stock, lui, ne les a pas encore vus partir.');
    }

    /* ── Le gel ── */

    public function testLaTailleDUnSacPrepareNeSuitPlusLeDossier(): void
    {
        [$season, $licencie] = $this->licencieDote('L');
        $besoin = $this->besoinDe($licencie);

        $this->preparation()->preparer($besoin);
        $licencie->getDossierClub()->setTailleHaut('M');
        $this->em->flush();

        $this->synchronizer()->syncTaillesFromDossiers($season);

        self::assertSame('L', $besoin->getTaille(), 'Le sac contient un L : le suivi doit continuer à le dire.');

        // Et la sortie du verrou rend bien la main à l'automate.
        $this->preparation()->annulerPreparation($besoin);
        $this->synchronizer()->syncTaillesFromDossiers($season);

        self::assertSame('M', $besoin->getTaille(), 'Dé-préparer, c\'est défaire le sac : la ligne repart du dossier.');
    }

    public function testUnBesoinPrepareSurvitAuRecalcul(): void
    {
        [, $licencie, $ligne] = $this->licencieDote('L');
        $besoin = $this->besoinDe($licencie);

        $this->preparation()->preparer($besoin);

        // Le kit change : la ligne disparaît du modèle après que le sac a été fait.
        $this->em->remove($ligne);
        $this->em->flush();
        $this->synchronizer()->recomputeForLicencie($licencie);

        self::assertCount(1, $this->besoins($licencie), 'Purger un sac déjà fait le laisserait sur les bras du club sans trace.');
        self::assertSame(DotationBesoinStatut::PREPARE, $this->besoinDe($licencie)->getStatut());
    }

    public function testLeFlocageNeSeCorrigePlusUneFoisLeSacFait(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Maillot', StockItemVetementType::HAUT);
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->preparation()->preparer($besoin);

        // Le flocage se commande bien avant le sac : à ce stade le vêtement est déjà marqué.
        $this->expectExceptionMessageMatches('/Dé-préparez/');
        $this->service(DotationFlocageService::class)->changer($besoin, 'DUPONT');
    }

    public function testLArticleServiNeSeRechoisitPlusUneFoisLeSacFait(): void
    {
        $scene = $this->transitionChaussettes();
        $besoin = $this->besoins($scene['licencie'])[0];

        $this->preparation()->preparer($besoin);

        $this->expectExceptionMessageMatches('/Dé-préparez/');
        $this->service(DotationEcoulementService::class)->fixerArticle($besoin, (string) $scene['erima']->getId());
    }

    /**
     * Le cas qui ment sans lever d'erreur : la dernière paire de l'ancien stock dort dans le
     * sac du second inscrit, et l'arbitrage — qui sert par ordre d'inscription — la promet au
     * premier. Deux lignes annoncent alors le même article, pour un seul carton.
     */
    public function testUnePairePrepareeNeSeReprometPasAQuelquUnDInscritPlusTot(): void
    {
        $scene = $this->transitionChaussettes(deux: true);
        $this->makeMovement($scene['nike'], 2, StockMovementType::ENTREE, '34');
        $this->allouer($scene['season']);

        $besoins = $this->besoinsDeLaSaison($scene['season']);
        self::assertTrue($besoins[0]->estServiParEcoulement() && $besoins[1]->estServiParEcoulement());

        // Le sac du second est fait, puis une paire part au rebut : il n'en reste qu'une.
        $this->preparation()->preparer($besoins[1]);
        $this->makeMovement($scene['nike'], 1, StockMovementType::REBUT, '34');
        $this->allouer($scene['season']);

        $besoins = $this->besoinsDeLaSaison($scene['season']);

        self::assertTrue($besoins[1]->estServiParEcoulement(), 'Le sac déjà fait garde sa paire.');
        self::assertFalse($besoins[0]->estServiParEcoulement(), 'Le premier inscrit repasse à l\'article du kit…');
        self::assertSame(
            ['Chaussettes ERIMA' => 1],
            $this->lignesACommander($scene['season']),
            '…et se commande, plutôt que de compter sur une paire qui n\'est plus disponible.',
        );
    }

    /**
     * Trois vestes en M au local, quatre licenciés qui en attendent une. Le compte est le
     * même avant et après préparation — une à commander —, mais la ligne qui le dit change :
     * elle doit désigner celui pour qui il ne reste rien, pas celui dont le sac est déjà fait.
     */
    public function testUnSacPrepareGardeSaVesteEtCEstLAutreQuiSeCommande(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($veste, 3, StockMovementType::ENTREE, 'M');

        $besoins = [];
        for ($i = 0; $i < 4; ++$i) {
            $besoins[] = $this->makeBesoin($season, $veste, 'M', 1);
        }
        $this->em->flush();

        self::assertSame(['Veste' => 1], $this->lignesACommander($season), '4 besoins − 3 en stock.');

        // Les trois derniers inscrits sont servis les premiers : leurs sacs sont faits.
        foreach (array_slice($besoins, 1) as $besoin) {
            $this->preparation()->preparer($besoin);
        }

        $provenances = $this->service(DotationProvenanceResolver::class)->parBesoin($season);

        self::assertSame(
            DotationProvenance::A_COMMANDER,
            $provenances[$besoins[0]->getId()],
            'Les trois vestes sont dans des sacs : il n\'en reste aucune pour le premier inscrit.',
        );
        foreach (array_slice($besoins, 1) as $besoin) {
            self::assertSame(
                DotationProvenance::EN_STOCK,
                $provenances[$besoin->getId()],
                'Un sac déjà fait tient sa veste, quel que soit l\'ordre d\'inscription.',
            );
        }

        self::assertSame(
            ['Veste' => 1],
            $this->lignesACommander($season),
            'Le bon de commande, lui, ne change pas : préparer ne sert ni ne consomme rien.',
        );
    }

    /* ── La suite du parcours ── */

    public function testLaRemiseSeFaitDepuisLeSacPrepare(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->preparation()->preparer($besoin);
        $this->service(DotationRemiseService::class)->marquerRemis($besoin, null);

        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut());
        self::assertNotNull($besoin->getMouvementSortie());
        self::assertSame(1, $this->mouvements()->getCurrentStockByTaille($item, 'L'));
    }

    public function testUnArticleRemisNeSePreparePlus(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->service(DotationRemiseService::class)->marquerRemis($besoin, null);

        $this->expectExceptionMessageMatches('/déjà été remis/');
        $this->preparation()->preparer($besoin);
    }

    public function testLAnnulationDUneRemiseRouvreLaLigneEntierement(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->preparation()->preparer($besoin);
        $remise = $this->service(DotationRemiseService::class);
        $remise->marquerRemis($besoin, null);

        self::assertSame(1, $this->mouvements()->getCurrentStockByTaille($item, 'L'), 'La veste est partie…');

        $remise->annulerRemise($besoin);

        self::assertSame(
            DotationBesoinStatut::A_DONNER,
            $besoin->getStatut(),
            'On ne sait pas si le sac existe encore : on ne repose pas un verrou que personne n\'a demandé.',
        );
        // Le point qui coûterait cher en silence : sans restitution, chaque remise annulée
        // creuserait le stock d'une unité et le club recommanderait par-dessus.
        self::assertSame(2, $this->mouvements()->getCurrentStockByTaille($item, 'L'), '…et revient en armoire.');
        self::assertNull($besoin->getMouvementSortie());
    }

    /* ── Ce que la fiche et le suivi en disent ── */

    public function testLaFicheAnnonceUneDotationPreteQuandToutEstMisDeCote(): void
    {
        [, $licencie] = $this->licencieDote('L', lignes: 2);
        $besoins = $this->besoins($licencie);

        $this->preparation()->preparer($besoins[0]);
        self::assertSame(
            DotationAvancementStatut::ATTENTE,
            $this->suivi()->avancementDe($licencie)?->statut,
            'Un sac à moitié fait n\'est pas un sac prêt.',
        );

        $this->preparation()->preparer($besoins[1]);
        $avancement = $this->suivi()->avancementDe($licencie);

        self::assertSame(DotationAvancementStatut::PREPAREE, $avancement->statut);
        self::assertSame('Dotation prête', $avancement->label());
        self::assertSame(2, $avancement->prepares);
    }

    public function testUneLignePrepareeSortDeLaListeDeFlocage(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Maillot', StockItemVetementType::HAUT);
        $besoin = $this->makeBesoin($season, $item, 'L', 1)->setPersonnalisation('DUPONT');
        $this->em->flush();

        self::assertCount(1, $this->suivi()->flocages($season));

        $this->preparation()->preparer($besoin);

        self::assertSame([], $this->suivi()->flocages($season), 'Le vêtement est déjà marqué : le refloquer serait une erreur.');
    }

    public function testLeSuiviCompteLesSacsPretsDuGroupe(): void
    {
        [$season, $licencie] = $this->licencieDote('L', lignes: 2);
        $besoins = $this->besoins($licencie);

        $this->preparation()->preparer($besoins[0]);

        $groupe = $this->suivi()->groupesDeSuivi($season)[0];

        self::assertSame(2, $groupe->restants, 'Préparé n\'est pas remis : la ligne reste à remettre.');
        self::assertSame(1, $groupe->prepares);
        self::assertSame(
            DotationBesoinStatut::A_DONNER,
            $groupe->besoins[0]->getStatut(),
            'Ce qui reste à sortir du carton passe devant ce qui est déjà dans le sac.',
        );
    }

    /* ── Décor ── */

    private function preparation(): DotationPreparationService
    {
        return $this->service(DotationPreparationService::class);
    }

    private function synchronizer(): DotationBesoinSynchronizer
    {
        return $this->service(DotationBesoinSynchronizer::class);
    }

    private function suivi(): DotationSuiviPresenter
    {
        return $this->service(DotationSuiviPresenter::class);
    }

    private function mouvements(): StockMovementRepository
    {
        return $this->service(StockMovementRepository::class);
    }

    /**
     * Un licencié validé, son kit résolu et ses besoins matérialisés.
     *
     * @return array{0: Season, 1: Licencie, 2: \App\Entity\DotationModeleLigne}
     */
    private function licencieDote(string $taille, int $lignes = 1): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $modele = $this->makeModele($season);

        $premiere = null;
        for ($i = 0; $i < $lignes; ++$i) {
            $ligne = $this->addLigne($modele, $this->makeItem('Veste ' . $i, StockItemVetementType::HAUT), 1);
            $premiere ??= $ligne;
        }

        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, $taille);
        $this->em->flush();

        $this->synchronizer()->recomputeForLicencie($licencie);

        return [$season, $licencie, $premiere];
    }

    /** @return list<DotationBesoin> */
    private function besoins(Licencie $licencie): array
    {
        $besoins = $this->service(DotationBesoinRepository::class)->findForLicencie($licencie);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }

    private function besoinDe(Licencie $licencie): DotationBesoin
    {
        return $this->besoins($licencie)[0];
    }

    /** @return list<DotationBesoin> */
    private function besoinsDeLaSaison(Season $season): array
    {
        $besoins = $this->service(DotationBesoinRepository::class)->findBySeason($season);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }

    /**
     * Le club est passé de Nike à ERIMA : le kit prévoit de l'ERIMA, il reste des Nike au local.
     *
     * @return array{season: Season, licencie: Licencie, erima: StockItem, nike: StockItem}
     */
    private function transitionChaussettes(bool $deux = false): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $erima = $this->makeItem('Chaussettes ERIMA', StockItemVetementType::CHAUSSURES)->setKind(StockItemKind::EQUIPEMENT);
        $nike = $this->makeItem('Chaussettes Nike', StockItemVetementType::CHAUSSURES)->setKind(StockItemKind::EQUIPEMENT);
        $nike->setRemplaceArticle($erima);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $erima, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $this->taille('34', \App\Enum\TailleType::POINTURE, proposee: true);
        $this->em->flush();

        $licencie = $this->makeLicencie($season, $cat, null, 'L', pointure: '34');
        $this->em->flush();
        $this->synchronizer()->recomputeForLicencie($licencie);

        if ($deux) {
            $second = $this->makeLicencie($season, $cat, null, 'L', pointure: '34');
            $this->em->flush();
            $this->synchronizer()->recomputeForLicencie($second);
        }

        return ['season' => $season, 'licencie' => $licencie, 'erima' => $erima, 'nike' => $nike];
    }

    private function allouer(Season $season): void
    {
        $this->em->flush();
        $this->service(DotationEcoulementAllocator::class)->allouer($season);
    }

    /** @return array<string, int> { nom d'article: quantité à commander } */
    private function lignesACommander(Season $season): array
    {
        $lignes = [];

        foreach ($this->service(AchatService::class)->computeACommander($season) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                $lignes[$ligne['stockItem']->getNom()] = $ligne['aCommander'];
            }
        }

        return $lignes;
    }
}

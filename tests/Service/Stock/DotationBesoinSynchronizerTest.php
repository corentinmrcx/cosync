<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Licencie;
use App\Enum\DotationAvancementStatut;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Stock\DotationBesoinSynchronizer;
use App\Service\Stock\DotationRemiseService;
use App\Service\Stock\DotationSuiviPresenter;

final class DotationBesoinSynchronizerTest extends StockIntegrationTestCase
{
    private function synchronizer(): DotationBesoinSynchronizer
    {
        return $this->service(DotationBesoinSynchronizer::class);
    }

    private function suivi(): DotationSuiviPresenter
    {
        return $this->service(DotationSuiviPresenter::class);
    }

    private function remise(): DotationRemiseService
    {
        return $this->service(DotationRemiseService::class);
    }

    private function besoinRepo(): DotationBesoinRepository
    {
        return $this->service(DotationBesoinRepository::class);
    }

    public function testRecomputeGenereLeBesoinALaBonneTaille(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'XL');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('XL', $besoins[0]->getTaille());
        self::assertSame(DotationBesoinStatut::A_DONNER, $besoins[0]->getStatut());
    }

    public function testRecomputeEstIdempotent(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $this->synchronizer()->recomputeForLicencie($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        self::assertCount(1, $this->besoinRepo()->findForLicencie($licencie), 'Pas de doublon après recalculs répétés.');
    }

    public function testRecomputePreserveUnBesoinDejaDonne(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        $this->remise()->marquerRemis($besoin, null);

        // Un nouveau recalcul ne doit ni dupliquer ni réinitialiser le besoin donné
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins);
        self::assertSame(DotationBesoinStatut::DONNE, $besoins[0]->getStatut());
    }

    public function testTailleManuellePreserveeAuRecalcul(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L'); // dossier → taille L

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('L', $besoin->getTaille());

        // L'admin force XXL à la main, puis on recalcule.
        $this->remise()->changerTaille($besoin, 'XXL');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('XXL', $besoin->getTaille(), 'La taille manuelle survit au recalcul.');
        self::assertTrue($besoin->isTailleManuelle());

        // Vider la taille repasse en automatique → le dossier (L) reprend la main.
        $this->remise()->changerTaille($besoin, '');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('L', $besoin->getTaille(), 'Taille vidée → retour à la déduction automatique.');
        self::assertFalse($besoin->isTailleManuelle());
    }

    public function testGroupeChoixDonnePuisChangementDeChoixNeDuplique(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $itemA = $this->makeItem('Veste rouge', StockItemVetementType::HAUT);
        $itemB = $this->makeItem('Veste bleue', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $itemA, 1, 'haut');
        $this->addLigne($modele, $itemB, 1, 'haut');
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins, 'Groupe de choix → 1 besoin (option par défaut).');

        // L'option par défaut est remise, puis le choix change vers l'autre option.
        $this->remise()->marquerRemis($besoins[0], null);
        $licencie->getDossierClub()->setDotationChoix(['haut' => $itemB->getId()]);
        $this->em->flush();
        $this->synchronizer()->recomputeForLicencie($licencie);

        self::assertCount(
            1,
            $this->besoinRepo()->findForLicencie($licencie),
            'Un groupe de choix déjà donné ne doit pas produire une ligne en doublon au changement de choix.',
        );
    }

    public function testRemiseCreeUnMouvementEtDecrementeLeStock(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L'); // stock L = 2
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->remise()->marquerRemis($besoin, null);

        $movRepo = $this->service(StockMovementRepository::class);
        self::assertSame(1, $movRepo->getCurrentStockByTaille($item, 'L'), 'Stock L = 2 − 1.');
        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut());

        $movement = $besoin->getMouvementSortie();
        self::assertNotNull($movement);
        self::assertSame(StockMovementType::SORTIE, $movement->getType());
        self::assertSame(StockMovementSource::DOTATION, $movement->getSource());
        self::assertSame('L', $movement->getTaille());
    }

    public function testFindBySeasonTriePersonneSansErreurDql(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $cat = $this->makeCategory('SENIOR');
        $zoe = $this->makeLicencie($season, $cat, null, 'L');
        $zoe->setNom('ZULU')->setPrenom('Zoe');
        $amir = $this->makeLicencie($season, $cat, null, 'M');
        $amir->setNom('ALPHA')->setPrenom('Amir');
        $this->makeBesoin($season, $item, 'L', 1)->setLicencie($zoe);
        $this->makeBesoin($season, $item, 'M', 1)->setLicencie($amir);
        $this->em->flush();

        $besoins = $this->besoinRepo()->findBySeason($season);

        self::assertCount(2, $besoins);
        self::assertSame('ALPHA', $besoins[0]->getLicencie()->getNom(), 'Trié par nom : ALPHA avant ZULU.');
        self::assertSame('ZULU', $besoins[1]->getLicencie()->getNom());
    }

    public function testGetSuiviGroupesRenvoieLesPersonnesServiesEnFin(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $team = $this->makeTeam($season, 'Séniors 1');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);

        // ALPHA : encore à servir. MIKE : entièrement servi. ZULU : encore à servir.
        $alpha = $this->makeLicencie($season, $cat, $team, 'L');
        $alpha->setNom('ALPHA')->setPrenom('Amir');
        $mike = $this->makeLicencie($season, $cat, $team, 'M');
        $mike->setNom('MIKE')->setPrenom('Mike');
        $zulu = $this->makeLicencie($season, $cat, $team, 'XL');
        $zulu->setNom('ZULU')->setPrenom('Zoe');

        $this->makeBesoin($season, $item, 'L', 1)->setLicencie($alpha);
        $this->makeBesoin($season, $item, 'M', 1, DotationBesoinStatut::DONNE)->setLicencie($mike);
        $this->makeBesoin($season, $item, 'XL', 1)->setLicencie($zulu);
        $this->em->flush();

        $groupes = $this->suivi()->groupesDeSuivi($season);

        self::assertCount(1, $groupes);
        self::assertSame('Séniors 1', $groupes[0]->nom);
        self::assertSame(3, $groupes[0]->total);
        self::assertSame(2, $groupes[0]->restants);

        $noms = array_map(static fn ($b) => $b->getLicencie()->getNom(), $groupes[0]->besoins);
        self::assertSame(['ALPHA', 'ZULU', 'MIKE'], $noms, 'À servir (alphabétique) puis servis en fin.');
    }

    public function testGetSuiviGroupesPersonnePartielleResteEnTete(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $team = $this->makeTeam($season, 'Séniors 1');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);

        // Une personne servie entièrement (WHISKEY) et une servie à moitié (BRAVO).
        $bravo = $this->makeLicencie($season, $cat, $team, 'L');
        $bravo->setNom('BRAVO')->setPrenom('Bob');
        $whiskey = $this->makeLicencie($season, $cat, $team, 'M');
        $whiskey->setNom('WHISKEY')->setPrenom('Will');

        $this->makeBesoin($season, $item, 'L', 1, DotationBesoinStatut::DONNE)->setLicencie($bravo);
        $this->makeBesoin($season, $item, 'L', 1)->setLicencie($bravo);
        $this->makeBesoin($season, $item, 'M', 1, DotationBesoinStatut::DONNE)->setLicencie($whiskey);
        $this->em->flush();

        $groupes = $this->suivi()->groupesDeSuivi($season);

        $noms = array_map(static fn ($b) => $b->getLicencie()->getNom(), $groupes[0]->besoins);
        self::assertSame(['BRAVO', 'BRAVO', 'WHISKEY'], $noms, 'Une dotation partielle reste devant les personnes entièrement servies.');
    }

    public function testRecalculNeTouchePasAuxBesoinsDUneAutreSaison(): void
    {
        $season = $this->makeSeason('2025-2026');
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        $this->em->flush();
        $this->synchronizer()->recomputeForLicencie($licencie);

        // Un besoin d'une autre saison rattaché à la même personne ne doit jamais entrer
        // dans le périmètre du recalcul — sinon il serait supprimé comme caduc.
        $autreSaison = $this->makeSeason('2024-2025');
        $etranger = (new \App\Entity\DotationBesoin())
            ->setSeason($autreSaison)
            ->setStockItem($this->makeItem('Sweat', StockItemVetementType::HAUT))
            ->setLicencie($licencie)
            ->setQuantite(1);
        $this->em->persist($etranger);
        $this->em->flush();
        $idEtranger = $etranger->getId();

        $this->synchronizer()->recomputeForLicencie($licencie);

        self::assertNotNull(
            $this->em->find(\App\Entity\DotationBesoin::class, $idEtranger),
            'Le besoin de la saison précédente doit survivre au recalcul.',
        );
    }

    public function testStatutFicheLicencie(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $itemA = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $itemB = $this->makeItem('Short', StockItemVetementType::BAS);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $itemA, 1);
        $this->addLigne($modele, $itemB, 1);
        $this->affecterCategorie($season, $modele, $cat);

        $autreCat = $this->makeCategory('U11');
        $sansKit = $this->makeLicencie($season, $autreCat, null, 'M');
        $prevu = $this->makeLicencie($season, $cat, null, 'L');
        $this->em->flush();

        // Sans kit applicable → null
        self::assertNull($this->suivi()->avancementDe($sansKit));

        // Kit applicable mais pas encore matérialisé → a_preparer
        self::assertSame(DotationAvancementStatut::A_PREPARER, $this->suivi()->avancementDe($prevu)?->statut);

        // Besoins matérialisés, rien donné → attente
        $this->synchronizer()->recomputeForLicencie($prevu);
        $statut = $this->suivi()->avancementDe($prevu);
        self::assertSame(DotationAvancementStatut::ATTENTE, $statut->statut);
        self::assertSame(0, $statut->donnes);
        self::assertSame(2, $statut->total);

        // Une partie donnée → partielle
        $besoins = $this->besoinRepo()->findForLicencie($prevu);
        $this->remise()->marquerRemis($besoins[0], null);
        $statut = $this->suivi()->avancementDe($prevu);
        self::assertSame(DotationAvancementStatut::PARTIELLE, $statut->statut);
        self::assertSame(1, $statut->donnes);

        // Tout donné → remise
        $this->remise()->marquerRemis($besoins[1], null);
        self::assertSame(DotationAvancementStatut::REMISE, $this->suivi()->avancementDe($prevu)?->statut);
    }

    public function testChangerTailleApresRemiseAjusteLeStock(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L'); // stock L = 2
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'M'); // stock M = 2
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->remise()->marquerRemis($besoin, null);

        $movRepo = $this->service(StockMovementRepository::class);
        self::assertSame(1, $movRepo->getCurrentStockByTaille($item, 'L'), 'L = 2 − 1 après remise.');
        self::assertSame(2, $movRepo->getCurrentStockByTaille($item, 'M'));

        // Le licencié avait pris du L mais il lui faut finalement du M.
        $this->remise()->changerTaille($besoin, 'M', null);

        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut(), 'Reste « donné ».');
        self::assertSame('M', $besoin->getTaille());
        self::assertSame(2, $movRepo->getCurrentStockByTaille($item, 'L'), 'Stock L restitué.');
        self::assertSame(1, $movRepo->getCurrentStockByTaille($item, 'M'), 'Stock M décrémenté.');
        self::assertNotNull($besoin->getMouvementSortie());
        self::assertSame('M', $besoin->getMouvementSortie()->getTaille(), 'Le mouvement pointe la nouvelle taille.');
    }

    public function testAnnulationRemiseRetablitLeStock(): void
    {
        $season = $this->makeSeason();
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->remise()->marquerRemis($besoin, null);
        $this->remise()->annulerRemise($besoin);

        $movRepo = $this->service(StockMovementRepository::class);
        self::assertSame(2, $movRepo->getCurrentStockByTaille($item, 'L'), 'Stock rétabli après annulation.');
        self::assertSame(DotationBesoinStatut::A_DONNER, $besoin->getStatut());
        self::assertNull($besoin->getMouvementSortie());
    }
}

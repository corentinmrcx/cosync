<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Licencie;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;
use App\Service\Stock\DotationBesoinService;

final class DotationBesoinServiceTest extends StockIntegrationTestCase
{
    private function besoinService(): DotationBesoinService
    {
        return $this->service(DotationBesoinService::class);
    }

    private function besoinRepo(): DotationBesoinRepository
    {
        return $this->service(DotationBesoinRepository::class);
    }

    public function testRecomputeGenereLeBesoinALaBonneTaille(): void
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'XL');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('XL', $besoins[0]->getTaille());
        self::assertSame(DotationBesoinStatut::A_DONNER, $besoins[0]->getStatut());
    }

    public function testRecomputeEstIdempotent(): void
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $this->besoinService()->recomputeForLicencie($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);

        self::assertCount(1, $this->besoinRepo()->findForLicencie($licencie), 'Pas de doublon après recalculs répétés.');
    }

    public function testRecomputePreserveUnBesoinDejaDonne(): void
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        $this->besoinService()->markGiven($besoin, null);

        // Un nouveau recalcul ne doit ni dupliquer ni réinitialiser le besoin donné
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins);
        self::assertSame(DotationBesoinStatut::DONNE, $besoins[0]->getStatut());
    }

    public function testTailleManuellePreserveeAuRecalcul(): void
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L'); // dossier → taille L

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('L', $besoin->getTaille());

        // L'admin force XXL à la main, puis on recalcule.
        $this->besoinService()->updateTaille($besoin, 'XXL');
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('XXL', $besoin->getTaille(), 'La taille manuelle survit au recalcul.');
        self::assertTrue($besoin->isTailleManuelle());

        // Vider la taille repasse en automatique → le dossier (L) reprend la main.
        $this->besoinService()->updateTaille($besoin, '');
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        self::assertSame('L', $besoin->getTaille(), 'Taille vidée → retour à la déduction automatique.');
        self::assertFalse($besoin->isTailleManuelle());
    }

    public function testRemiseCreeUnMouvementEtDecrementeLeStock(): void
    {
        $season = $this->makeSeason();
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L'); // stock L = 2
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->besoinService()->markGiven($besoin, null);

        $movRepo = $this->service(StockMovementRepository::class);
        self::assertSame(1, $movRepo->getCurrentStockByTaille($item, 'L'), 'Stock L = 2 − 1.');
        self::assertSame(DotationBesoinStatut::DONNE, $besoin->getStatut());

        $movement = $besoin->getMouvementSortie();
        self::assertNotNull($movement);
        self::assertSame(StockMovementType::SORTIE, $movement->getType());
        self::assertSame(StockMovementSource::DOTATION, $movement->getSource());
        self::assertSame('L', $movement->getTaille());
    }

    public function testAnnulationRemiseRetablitLeStock(): void
    {
        $season = $this->makeSeason();
        $item   = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->makeMovement($item, 2, StockMovementType::ENTREE, 'L');
        $besoin = $this->makeBesoin($season, $item, 'L', 1);
        $this->em->flush();

        $this->besoinService()->markGiven($besoin, null);
        $this->besoinService()->cancelGiven($besoin);

        $movRepo = $this->service(StockMovementRepository::class);
        self::assertSame(2, $movRepo->getCurrentStockByTaille($item, 'L'), 'Stock rétabli après annulation.');
        self::assertSame(DotationBesoinStatut::A_DONNER, $besoin->getStatut());
        self::assertNull($besoin->getMouvementSortie());
    }
}

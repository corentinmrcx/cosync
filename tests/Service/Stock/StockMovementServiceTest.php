<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\ManualMovementData;
use App\Enum\LicenceStatus;
use App\Enum\StockActionManuelle;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockMovementService;

final class StockMovementServiceTest extends StockIntegrationTestCase
{
    private function mouvements(): StockMovementService
    {
        return $this->service(StockMovementService::class);
    }

    public function testRecordManualMovementEntreeIncrementeLeStock(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $data = new ManualMovementData(StockActionManuelle::ENTREE, 5, 'L', null, null);
        $movement = $this->mouvements()->recordManualMovement($veste, $data, null);

        self::assertSame(StockMovementType::ENTREE, $movement->getType());
        self::assertSame(StockMovementSource::MANUEL, $movement->getSource());
        self::assertSame(5, $this->mouvements()->getCurrentStock($veste));
    }

    public function testRecordManualMovementDotationSansLicencieEstRefusee(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->mouvements()->recordManualMovement($veste, new ManualMovementData(StockActionManuelle::DOTATION, 1, 'L', null, null), null);
    }

    public function testRecordManualMovementDotationLicencieNonValideEstRefusee(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory();
        $licencie = $this->makeLicencie($season, $cat, status: LicenceStatus::FORM_COMPLETED);
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->mouvements()->recordManualMovement(
            $veste,
            new ManualMovementData(StockActionManuelle::DOTATION, 1, 'L', null, (string) $licencie->getUuid()),
            null,
        );
    }

    /**
     * La sortie de stock est ouverte dès le **solde** : la validation FootClubs qui suit est
     * une démarche du club, elle n'a pas à retarder la remise d'un kit déjà payé.
     */
    public function testRecordManualMovementDotationLicencieSoldeEstAcceptee(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory();
        $licencie = $this->makeLicencie($season, $cat, status: LicenceStatus::A_VALIDER_FFF);
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->mouvements()->recordManualMovement($veste, new ManualMovementData(StockActionManuelle::ENTREE, 5, 'L', null, null), null);

        $movement = $this->mouvements()->recordManualMovement(
            $veste,
            new ManualMovementData(StockActionManuelle::DOTATION, 1, 'L', null, (string) $licencie->getUuid()),
            null,
        );

        self::assertSame(StockMovementType::SORTIE, $movement->getType());
        self::assertSame(4, $this->mouvements()->getCurrentStock($veste));
    }

    public function testRecordManualMovementSortieAuDelaDuStockEstRefusee(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->mouvements()->recordManualMovement($veste, new ManualMovementData(StockActionManuelle::SORTIE, 3, 'L', null, null), null);
    }

    public function testDeleteManualMovementRecalculeLeStock(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $movement = $this->mouvements()->recordManualMovement($veste, new ManualMovementData(StockActionManuelle::ENTREE, 5, 'L', null, null), null);
        self::assertSame(5, $this->mouvements()->getCurrentStock($veste));

        $this->mouvements()->deleteManualMovement($movement);
        self::assertSame(0, $this->mouvements()->getCurrentStock($veste));
    }

    public function testDeleteMouvementNonManuelEstRefuse(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        // Mouvement d'origine commande → non supprimable depuis l'historique.
        $movement = $this->mouvements()->recordMovement(
            $veste, 3, StockMovementType::ENTREE, StockMovementSource::COMMANDE, null, 'Réception', taille: 'L',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->mouvements()->deleteManualMovement($movement);
    }
}

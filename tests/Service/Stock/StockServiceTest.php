<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\ManualMovementData;
use App\Enum\LicenceStatus;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockService;

final class StockServiceTest extends StockIntegrationTestCase
{
    private function stockService(): StockService
    {
        return $this->service(StockService::class);
    }

    public function testRecordManualMovementEntreeIncrementeLeStock(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $data = new ManualMovementData('entree', 5, 'L', null, null);
        $movement = $this->stockService()->recordManualMovement($veste, $data, null);

        self::assertSame(StockMovementType::ENTREE, $movement->getType());
        self::assertSame(StockMovementSource::MANUEL, $movement->getSource());
        self::assertSame(5, $this->stockService()->getCurrentStock($veste));
    }

    public function testRecordManualMovementDotationSansLicencieEstRefusee(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->stockService()->recordManualMovement($veste, new ManualMovementData('dotation', 1, 'L', null, null), null);
    }

    public function testRecordManualMovementDotationLicencieNonValideEstRefusee(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory();
        $licencie = $this->makeLicencie($season, $cat, status: LicenceStatus::FORM_COMPLETED);
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->stockService()->recordManualMovement(
            $veste,
            new ManualMovementData('dotation', 1, 'L', null, (string) $licencie->getUuid()),
            null,
        );
    }

    public function testRecordManualMovementSortieAuDelaDuStockEstRefusee(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->stockService()->recordManualMovement($veste, new ManualMovementData('sortie', 3, 'L', null, null), null);
    }

    public function testDeleteManualMovementRecalculeLeStock(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        $movement = $this->stockService()->recordManualMovement($veste, new ManualMovementData('entree', 5, 'L', null, null), null);
        self::assertSame(5, $this->stockService()->getCurrentStock($veste));

        $this->stockService()->deleteManualMovement($movement);
        self::assertSame(0, $this->stockService()->getCurrentStock($veste));
    }

    public function testDeleteMouvementNonManuelEstRefuse(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $this->em->flush();

        // Mouvement d'origine commande → non supprimable depuis l'historique.
        $movement = $this->stockService()->recordMovement(
            $veste, 3, StockMovementType::ENTREE, StockMovementSource::COMMANDE, null, 'Réception', taille: 'L',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->stockService()->deleteManualMovement($movement);
    }
}

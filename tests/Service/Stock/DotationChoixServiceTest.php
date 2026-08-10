<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Licencie;
use App\Enum\StockItemVetementType;
use App\Repository\DotationBesoinRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationChoixService;
use App\Service\Dotation\DotationRemiseService;

/**
 * Correction par l'admin de l'option retenue dans un groupe de choix.
 *
 * Le cas qui la rend indispensable : un kit créé après que des licences ont été validées.
 * Les dossiers ne portent alors aucune réponse, et le résolveur retient par repli la
 * première option — tout le monde se retrouve avec le même article, et le « à commander »
 * le compte comme tel.
 */
final class DotationChoixServiceTest extends StockIntegrationTestCase
{
    private function choix(): DotationChoixService
    {
        return $this->service(DotationChoixService::class);
    }

    private function synchronizer(): DotationBesoinSynchronizer
    {
        return $this->service(DotationBesoinSynchronizer::class);
    }

    private function besoinRepo(): DotationBesoinRepository
    {
        return $this->service(DotationBesoinRepository::class);
    }

    public function testSansReponseDuLicencieLeRepliRetientLaPremiereOption(): void
    {
        [$licencie, $veste] = $this->scenarioDeuxOptions();

        $besoins = $this->besoinRepo()->findForLicencie($licencie);

        self::assertCount(1, $besoins);
        self::assertSame($veste->getId(), $besoins[0]->getStockItem()->getId());
    }

    public function testLaCorrectionRemplaceLOptionRetenue(): void
    {
        [$licencie, , $sweat] = $this->scenarioDeuxOptions();
        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];

        $this->choix()->corriger($besoin, $sweat);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertCount(1, $besoins, 'La correction remplace l\'option, elle n\'en ajoute pas une seconde.');
        self::assertSame($sweat->getId(), $besoins[0]->getStockItem()->getId());
    }

    /**
     * Le cœur du correctif : la correction s'écrit dans le dossier, pas sur le besoin. Un
     * recalcul — déclenché à chaque ouverture du suivi — ne doit pas la ramener au repli.
     */
    public function testLaCorrectionSurvitAUnRecalcul(): void
    {
        [$licencie, , $sweat] = $this->scenarioDeuxOptions();
        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];

        $this->choix()->corriger($besoin, $sweat);

        // Le suivi relance un recalcul à chaque ouverture : il ne doit pas ramener le repli.
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinRepo()->findForLicencie($licencie);
        self::assertSame($sweat->getId(), $besoins[0]->getStockItem()->getId());
        self::assertSame(
            ['Choix du haut' => $sweat->getId()],
            $licencie->getDossierClub()->getDotationChoix(),
            'La correction vit dans le dossier : c\'est lui que relit le résolveur.',
        );
    }

    public function testUnArticleEtrangerAuChoixEstRefuse(): void
    {
        [$licencie] = $this->scenarioDeuxOptions();
        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        $intrus = $this->makeItem('Casquette', null);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->choix()->corriger($besoin, $intrus);
    }

    /** Une fois l'article remis, le stock est sorti : la correction passe par l'annulation. */
    public function testUnArticleDejaRemisNeChangePlusDOption(): void
    {
        [$licencie, , $sweat] = $this->scenarioDeuxOptions();
        $besoin = $this->besoinRepo()->findForLicencie($licencie)[0];
        $this->service(DotationRemiseService::class)->marquerRemis($besoin, null);

        $this->expectException(\DomainException::class);
        $this->choix()->corriger($besoin, $sweat);
    }

    /**
     * @return array{0: Licencie, 1: \App\Entity\StockItem, 2: \App\Entity\StockItem}
     */
    private function scenarioDeuxOptions(): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $sweat = $this->makeItem('Sweat', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $veste, 1, 'Choix du haut');
        $this->addLigne($modele, $sweat, 1, 'Choix du haut');
        $this->affecterCategorie($season, $modele, $cat);

        // Licencié inscrit avant l'existence du kit : son dossier ne porte aucune réponse.
        $licencie = $this->makeLicencie($season, $cat, null, 'XL');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->synchronizer()->recomputeForLicencie($licencie);

        return [$licencie, $veste, $sweat];
    }
}

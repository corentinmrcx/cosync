<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Enum\CommandeStatut;
use App\Enum\StockItemVetementType;
use App\Repository\StockMovementRepository;
use App\Service\Stock\CommandeService;

final class CommandeServiceTest extends StockIntegrationTestCase
{
    private function commandeService(): CommandeService
    {
        return $this->service(CommandeService::class);
    }

    public function testGenererBonsCreeUnBrouillonParFournisseurAvecPrix(): void
    {
        $season = $this->makeSeason();
        $f      = $this->makeFournisseur('Sport2000');
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT, $f);
        $veste->setPrixAchat(20.0);
        $this->makeBesoin($season, $veste, 'L', 4);
        $this->em->flush();

        $bons = $this->commandeService()->genererBons($season);

        self::assertCount(1, $bons);
        $commande = $bons[0];
        self::assertSame(CommandeStatut::BROUILLON, $commande->getStatut());
        self::assertSame('Sport2000', $commande->getFournisseurLabel());
        self::assertCount(1, $commande->getLignes());

        /** @var CommandeLigne $ligne */
        $ligne = $commande->getLignes()->first();
        self::assertSame('L', $ligne->getTaille());
        self::assertSame(4, $ligne->getQuantite());
        self::assertSame(20.0, $ligne->getPrixUnitaire());
    }

    public function testGenererBonsDeuxFoisNeCreePasDeDoublon(): void
    {
        $season = $this->makeSeason();
        $f      = $this->makeFournisseur('Sport2000');
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT, $f);
        $this->makeBesoin($season, $veste, 'L', 4);
        $this->em->flush();

        $this->commandeService()->genererBons($season);
        $this->commandeService()->genererBons($season); // re-génération sans avoir marqué « commandée »

        $brouillons = $this->em->getRepository(Commande::class)->findBy([
            'season' => $season,
            'statut' => CommandeStatut::BROUILLON,
        ]);
        self::assertCount(1, $brouillons, 'Les brouillons sont régénérés, pas dupliqués.');
    }

    public function testGenererBonsNeTouchePasUneCommandePassee(): void
    {
        $season = $this->makeSeason();
        $f      = $this->makeFournisseur('Sport2000');
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT, $f);
        // Une commande déjà passée couvre 2 sur un besoin de 5 → reste 3 à commander.
        $this->makeBesoin($season, $veste, 'L', 5);
        $this->makeCommandeEnAttente($season, $veste, 'L', 2, $f);
        $this->em->flush();

        $bons = $this->commandeService()->genererBons($season);

        self::assertCount(1, $bons);
        self::assertSame(3, $bons[0]->getLignes()->first()->getQuantite(), '5 − 2 déjà en commande.');
        // La commande passée existe toujours (1 passée + 1 brouillon).
        self::assertCount(2, $this->em->getRepository(Commande::class)->findBy(['season' => $season]));
    }

    public function testReceptionPartiellePuisComplete(): void
    {
        $season = $this->makeSeason();
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $ligne  = $this->makeCommandeEnAttente($season, $veste, 'L', 3); // commande COMMANDEE, 3 attendus
        $this->em->flush();

        $movRepo = $this->service(StockMovementRepository::class);

        // Réception de 1 sur 3 → partielle
        $this->commandeService()->recevoirLigne($ligne, 1, null);
        self::assertSame(1, $ligne->getQuantiteRecue());
        self::assertSame(1, $movRepo->getCurrentStockByTaille($veste, 'L'));
        self::assertSame(CommandeStatut::RECUE_PARTIELLE, $ligne->getCommande()->getStatut());

        // Réception du reste → complète
        $this->commandeService()->recevoirLigne($ligne, 2, null);
        self::assertSame(3, $ligne->getQuantiteRecue());
        self::assertSame(3, $movRepo->getCurrentStockByTaille($veste, 'L'));
        self::assertSame(CommandeStatut::RECUE, $ligne->getCommande()->getStatut());
    }

    public function testReceptionBorneeAuRestant(): void
    {
        $season = $this->makeSeason();
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $ligne  = $this->makeCommandeEnAttente($season, $veste, 'M', 2);
        $this->em->flush();

        // On tente de recevoir 10 alors qu'il n'en reste que 2
        $this->commandeService()->recevoirLigne($ligne, 10, null);

        self::assertSame(2, $ligne->getQuantiteRecue(), 'Borné au restant.');
        self::assertSame(2, $this->service(StockMovementRepository::class)->getCurrentStockByTaille($veste, 'M'));
        self::assertSame(CommandeStatut::RECUE, $ligne->getCommande()->getStatut());
    }

    public function testAnnulerReceptionRevientStockEtStatut(): void
    {
        $season = $this->makeSeason();
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $ligne  = $this->makeCommandeEnAttente($season, $veste, 'L', 3);
        $this->em->flush();

        $movRepo = $this->service(StockMovementRepository::class);

        $this->commandeService()->recevoirLigne($ligne, 2, null);
        self::assertSame(2, $movRepo->getCurrentStockByTaille($veste, 'L'));
        self::assertSame(CommandeStatut::RECUE_PARTIELLE, $ligne->getCommande()->getStatut());

        $this->commandeService()->annulerReception($ligne, null);

        self::assertSame(0, $ligne->getQuantiteRecue(), 'Reçu remis à zéro.');
        self::assertSame(0, $movRepo->getCurrentStockByTaille($veste, 'L'), 'Stock réversé par mouvement compensatoire.');
        self::assertSame(CommandeStatut::COMMANDEE, $ligne->getCommande()->getStatut(), 'Retour au statut commandée.');
    }

    public function testMarquerCommandeePoseLaDate(): void
    {
        $season   = $this->makeSeason();
        $veste    = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $commande = (new Commande())->setSeason($season);
        $this->em->persist($commande);
        $this->em->flush();

        $date = new \DateTimeImmutable('2026-06-28');
        $this->commandeService()->marquerCommandee($commande, $date);

        self::assertSame(CommandeStatut::COMMANDEE, $commande->getStatut());
        self::assertEquals($date, $commande->getDateCommande());
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationEligibilite;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use App\Repository\DotationBesoinRepository;
use App\Service\Stock\DotationBesoinService;

/**
 * Propagation du texte de flocage, du dossier du licencié jusqu'au besoin matérialisé.
 *
 * Point critique : corriger une faute de frappe doit mettre à jour le besoin EN PLACE.
 * Si le texte entrait dans l'identité de l'emplacement (slotKey), la correction détruirait
 * le besoin pour en recréer un autre, perdant statut, taille manuelle et historique.
 */
final class DotationPersonnalisationTest extends StockIntegrationTestCase
{
    private function besoinService(): DotationBesoinService
    {
        return $this->service(DotationBesoinService::class);
    }

    /** @return DotationBesoin[] */
    private function besoinsDe(Licencie $licencie): array
    {
        /** @var DotationBesoinRepository $repo */
        $repo = $this->service(DotationBesoinRepository::class);

        return $repo->findForLicencie($licencie);
    }

    /** Kit avec un t-shirt floqué imposé (option unique éligible). @return array{0: Licencie} */
    private function licencieAvecTshirtFloque(string $texte = 'Coco', ?int $max = null): array
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $tshirt, 1, null, DotationEligibilite::TOUS, true, $max);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        $this->em->flush();

        $ligne = $modele->getLignes()->first();
        $this->setReponsesFormulaire($licencie, [], ['ligne:' . $ligne->getId() => $texte]);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        return [$licencie];
    }

    public function testTextePropageDuDossierVersLeBesoin(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');

        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('Coco', $besoins[0]->getPersonnalisation());
    }

    public function testCorrectionDuTexteMetAJourLeBesoinSansLeRecreer(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Cco');

        $this->besoinService()->recomputeForLicencie($licencie);
        $idAvant = $this->besoinsDe($licencie)[0]->getId();

        // Le licencié (ou l'admin) corrige la faute de frappe dans le dossier.
        $dossier = $licencie->getDossierClub();
        $cles    = array_keys($dossier->getDotationPersonnalisation());
        $dossier->setDotationPersonnalisation([$cles[0] => 'Coco']);
        $this->em->flush();

        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins, 'Aucun doublon : le besoin est mis à jour en place.');
        self::assertSame($idAvant, $besoins[0]->getId(), 'Le besoin conserve son identité.');
        self::assertSame('Coco', $besoins[0]->getPersonnalisation());
    }

    public function testBesoinDejaDonneConserveSonTexte(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');

        $this->besoinService()->recomputeForLicencie($licencie);
        $besoin = $this->besoinsDe($licencie)[0];
        $besoin->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        // Le dossier change après la remise : le vêtement est déjà floqué, on n'y touche plus.
        $dossier = $licencie->getDossierClub();
        $cles    = array_keys($dossier->getDotationPersonnalisation());
        $dossier->setDotationPersonnalisation([$cles[0] => 'Autre chose']);
        $this->em->flush();

        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('Coco', $besoins[0]->getPersonnalisation(), 'Le texte réellement floqué reste la trace.');
    }

    public function testTextePerimeSurUneOptionNonPersonnaliseeNeRemontePas(): void
    {
        $season = $this->makeSeason();
        $cat    = $this->makeCategory('SENIOR');
        $veste  = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $veste, 1, 'Dotation');                                   // non floquée
        $this->addLigne($modele, $tshirt, 1, 'Dotation', DotationEligibilite::TOUS, true);  // floquée
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        $this->em->flush();
        // Il avait saisi un texte pour le t-shirt puis a finalement pris la veste.
        $this->setReponsesFormulaire($licencie, ['Dotation' => $veste->getId()], ['Dotation' => 'Coco']);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertSame('Veste', $besoins[0]->getStockItem()->getNom());
        self::assertNull($besoins[0]->getPersonnalisation(), 'Une veste non floquée ne part jamais avec un texte.');
    }

    public function testUpdatePersonnalisationCorrigeLeTexte(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Cco');
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $this->besoinService()->updatePersonnalisation($besoin, '  Coco   Bel ');

        self::assertSame('Coco Bel', $besoin->getPersonnalisation(), 'Trim + espaces compactés.');
    }

    public function testUpdatePersonnalisationRefuseeApresRemise(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');
        $this->besoinService()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $besoin->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->besoinService()->updatePersonnalisation($besoin, 'Trop tard');
    }

    public function testGetFlocagesListeUniquementLesArticlesFloquesADonner(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');
        $this->besoinService()->recomputeForLicencie($licencie);

        $season   = $licencie->getSeason();
        $flocages = $this->besoinService()->getFlocages($season);

        self::assertCount(1, $flocages);
        self::assertSame('Coco', $flocages[0]->getPersonnalisation());

        // Une fois remis, l'article sort de la liste à transmettre au floqueur.
        $flocages[0]->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        self::assertSame([], $this->besoinService()->getFlocages($season));
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationEligibilite;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use App\Repository\DotationBesoinRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationFlocageService;
use App\Service\Dotation\DotationResolver;
use App\Service\Dotation\DotationSuiviPresenter;

/**
 * Propagation du texte de flocage, du dossier du licencié jusqu'au besoin matérialisé.
 *
 * Point critique : corriger une faute de frappe doit mettre à jour le besoin EN PLACE.
 * Si le texte entrait dans l'identité de l'emplacement (slotKey), la correction détruirait
 * le besoin pour en recréer un autre, perdant statut, taille manuelle et historique.
 */
final class DotationPersonnalisationTest extends StockIntegrationTestCase
{
    private function synchronizer(): DotationBesoinSynchronizer
    {
        return $this->service(DotationBesoinSynchronizer::class);
    }

    private function suivi(): DotationSuiviPresenter
    {
        return $this->service(DotationSuiviPresenter::class);
    }

    private function flocage(): DotationFlocageService
    {
        return $this->service(DotationFlocageService::class);
    }

    /** @return DotationBesoin[] */
    private function besoinsDe(Licencie $licencie): array
    {
        /** @var DotationBesoinRepository $repo */
        $repo = $this->service(DotationBesoinRepository::class);

        return $repo->findForLicencie($licencie);
    }

    /**
     * Kit avec un t-shirt floqué imposé (option unique éligible). Un texte vide simule le
     * licencié qui n'a jamais répondu au formulaire : son dossier ne porte alors aucune clé.
     *
     * @return array{0: Licencie, 1: int} le licencié et l'id de la ligne du kit
     */
    private function licencieAvecTshirtFloque(string $texte = 'Coco', ?int $max = null): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $tshirt, 1, null, DotationEligibilite::TOUS, true, $max);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        $this->em->flush();

        $ligne = $modele->getLignes()->first();
        $this->setReponsesFormulaire($licencie, [], $texte !== '' ? ['ligne:' . $ligne->getId() => $texte] : []);

        $ligneId = $ligne->getId();

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        return [$licencie, $ligneId];
    }

    public function testTextePropageDuDossierVersLeBesoin(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');

        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('Coco', $besoins[0]->getPersonnalisation());
    }

    public function testCorrectionDuTexteMetAJourLeBesoinSansLeRecreer(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Cco');

        $this->synchronizer()->recomputeForLicencie($licencie);
        $idAvant = $this->besoinsDe($licencie)[0]->getId();

        // Le licencié (ou l'admin) corrige la faute de frappe dans le dossier.
        $dossier = $licencie->getDossierClub();
        $cles = array_keys($dossier->getDotationPersonnalisation());
        $dossier->setDotationPersonnalisation([$cles[0] => 'Coco']);
        $this->em->flush();

        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins, 'Aucun doublon : le besoin est mis à jour en place.');
        self::assertSame($idAvant, $besoins[0]->getId(), 'Le besoin conserve son identité.');
        self::assertSame('Coco', $besoins[0]->getPersonnalisation());
    }

    public function testBesoinDejaDonneConserveSonTexte(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');

        $this->synchronizer()->recomputeForLicencie($licencie);
        $besoin = $this->besoinsDe($licencie)[0];
        $besoin->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        // Le dossier change après la remise : le vêtement est déjà floqué, on n'y touche plus.
        $dossier = $licencie->getDossierClub();
        $cles = array_keys($dossier->getDotationPersonnalisation());
        $dossier->setDotationPersonnalisation([$cles[0] => 'Autre chose']);
        $this->em->flush();

        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('Coco', $besoins[0]->getPersonnalisation(), 'Le texte réellement floqué reste la trace.');
    }

    public function testTextePerimeSurUneOptionNonPersonnaliseeNeRemontePas(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
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
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertSame('Veste', $besoins[0]->getStockItem()->getNom());
        self::assertNull($besoins[0]->getPersonnalisation(), 'Une veste non floquée ne part jamais avec un texte.');
    }

    public function testUpdatePersonnalisationCorrigeLeTexte(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Cco');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $this->flocage()->changer($besoin, '  Coco   Bel ');

        self::assertSame('Coco Bel', $besoin->getPersonnalisation(), 'Trim + espaces compactés.');
    }

    public function testUpdatePersonnalisationRefuseeApresRemise(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $besoin->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->flocage()->changer($besoin, 'Trop tard');
    }

    /**
     * Le cas qui manquait : le licencié n'a jamais rempli son formulaire — kit créé après la
     * validation de sa licence, ou incident de saisie. Le club doit pouvoir renseigner le
     * flocage depuis le suivi, et surtout le retrouver après un « Recalculer les besoins ».
     */
    public function testFlocageSaisiParLAdminSurvitAuRecalcul(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        self::assertNull($besoin->getPersonnalisation(), 'Rien n\'a été saisi au formulaire.');
        self::assertNotNull(
            $this->flocage()->reglagesPour($besoin),
            'L\'article se floque : le suivi doit proposer la saisie même sans texte.',
        );

        $this->flocage()->changer($besoin, 'Coco');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoins = $this->besoinsDe($licencie);
        self::assertCount(1, $besoins);
        self::assertSame('Coco', $besoins[0]->getPersonnalisation(), 'Le dossier vide ne l\'efface plus.');
    }

    /** Vider le champ rend la ligne au dossier, comme une taille effacée. */
    public function testViderLeFlocageRelacheLeVerrou(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $this->flocage()->changer($besoin, 'Saisi à la main');
        $this->flocage()->changer($besoin, '');

        self::assertNull($besoin->getPersonnalisation());
        self::assertFalse($besoin->isPersonnalisationManuelle());

        $this->synchronizer()->recomputeForLicencie($licencie);

        self::assertSame('Coco', $this->besoinsDe($licencie)[0]->getPersonnalisation(), 'Le dossier reprend la main.');
    }

    /** Le kit garde le dernier mot : une option qui ne se floque plus n'emporte aucun texte. */
    public function testUnArticleQuiNeSeFloquePlusPerdSonTexteManuel(): void
    {
        [$licencie, $ligneId] = $this->licencieAvecTshirtFloque('');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        $this->flocage()->changer($besoin, 'Coco');

        // L'admin retire le flocage de la ligne du kit.
        $this->em->find(DotationModeleLigne::class, $ligneId)->setPersonnalisationRequise(false);
        $this->em->flush();

        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];
        self::assertNull($besoin->getPersonnalisation());
        self::assertFalse($besoin->isPersonnalisationManuelle(), 'Le verrou tombe avec le flocage.');
    }

    /** Un texte plus long que ce que le kit autorise est refusé, pas tronqué en silence. */
    public function testFlocageTropLongRefuse(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco', max: 6);
        $this->synchronizer()->recomputeForLicencie($licencie);

        $besoin = $this->besoinsDe($licencie)[0];

        $this->expectException(\DomainException::class);
        $this->flocage()->changer($besoin, 'Beaucoup trop long');
    }

    /**
     * Le champ de flocage d'une option de choix est porté par la question elle-même, conditionné
     * à la sélection. Il ne doit jamais ressortir en plus comme personnalisation « automatique »,
     * sinon le formulaire public affiche deux fois le même champ sous la même clé — et le second,
     * inconditionnel, rend le flocage obligatoire même quand l'option choisie n'en demande aucun.
     */
    public function testUneOptionDeChoixNeRessortPasEnPersonnalisationAutomatique(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);
        $sac = $this->makeItem('Sac à dos', null);

        $modele = $this->makeModele($season, 'Dotation 2026');
        // Première option du groupe, floquée : c'est elle que le repli de retainedLines() faisait
        // remonter à tort quand aucun choix n'était encore fait.
        $this->addLigne($modele, $tshirt, 1, 'Votre dotation', DotationEligibilite::TOUS, true);
        $this->addLigne($modele, $veste, 1, 'Votre dotation');
        $ligneSac = $this->addLigne($modele, $sac, 1, null, DotationEligibilite::TOUS, true); // article fixe floqué
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $resolver = $this->service(DotationResolver::class);

        self::assertCount(1, $resolver->getChoiceGroups($licencie), 'Le groupe est bien posé en question.');

        $autos = $resolver->getAutoPersonnalisationRequests($licencie);
        self::assertSame(
            ['ligne:' . $ligneSac->getId()],
            array_column($autos, 'cle'),
            'Seul l\'article fixe floqué est demandé à part ; le t-shirt passe par sa question.',
        );
    }

    /** Un groupe réduit à une seule option éligible n'est plus une question : son texte est dû à part. */
    public function testUnGroupeAutoResoluRessortEnPersonnalisationAutomatique(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $veste, 1, 'Votre dotation', DotationEligibilite::NOUVEAUX, true);
        $this->addLigne($modele, $tshirt, 1, 'Votre dotation', DotationEligibilite::RENOUVELLEMENTS);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::NOUVELLE_DEMANDE);
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $resolver = $this->service(DotationResolver::class);

        self::assertSame([], $resolver->getChoiceGroups($licencie), 'Un nouveau licencié n\'a pas le choix.');
        self::assertSame(
            ['Votre dotation'],
            array_column($resolver->getAutoPersonnalisationRequests($licencie), 'cle'),
            'La veste imposée réclame quand même son texte.',
        );
    }

    public function testGetFlocagesListeUniquementLesArticlesFloquesADonner(): void
    {
        [$licencie] = $this->licencieAvecTshirtFloque('Coco');
        $this->synchronizer()->recomputeForLicencie($licencie);

        $season = $licencie->getSeason();
        $flocages = $this->suivi()->flocages($season);

        self::assertCount(1, $flocages);
        self::assertSame('Coco', $flocages[0]->getPersonnalisation());

        // Une fois remis, l'article sort de la liste à transmettre au floqueur.
        $flocages[0]->setStatut(DotationBesoinStatut::DONNE);
        $this->em->flush();

        self::assertSame([], $this->suivi()->flocages($season));
    }
}

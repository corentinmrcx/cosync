<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Enum\DotationEligibilite;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use App\Service\Stock\DotationResolver;

/**
 * Éligibilité des options de dotation selon la nature de la licence.
 *
 * Scénario de référence de la saison : un groupe « Dotation » propose une veste ouverte à tous
 * et un t-shirt réservé aux renouvellements. Un nouveau ne voit donc aucune question et reçoit
 * la veste ; un renouvellement garde le choix entre les deux.
 */
final class DotationEligibiliteTest extends StockIntegrationTestCase
{
    private function resolver(): DotationResolver
    {
        return $this->service(DotationResolver::class);
    }

    /** @return array{0: \App\Entity\Season, 1: \App\Entity\Category, 2: \App\Entity\DotationModele, 3: \App\Entity\StockItem} */
    private function kitVesteOuTshirt(): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $veste, 1, 'Dotation', DotationEligibilite::TOUS);
        $this->addLigne($modele, $tshirt, 1, 'Dotation', DotationEligibilite::RENOUVELLEMENTS);
        $this->affecterCategorie($season, $modele, $cat);

        return [$season, $cat, $modele, $tshirt];
    }

    public function testNouveauLicencieNeVoitAucuneQuestionEtRecoitLaVeste(): void
    {
        [$season, $cat] = $this->kitVesteOuTshirt();
        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::NOUVELLE_DEMANDE);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertSame([], $this->resolver()->getChoiceGroups($licencie), 'Une seule option éligible → aucune question.');

        $lignes = $this->resolver()->resolveDotation($licencie);
        self::assertCount(1, $lignes);
        self::assertSame('Veste', $lignes[0]['stockItem']->getNom());
    }

    public function testMuteEstTraiteCommeUnNouveau(): void
    {
        [$season, $cat] = $this->kitVesteOuTshirt();
        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::CHANGEMENT_CLUB);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertSame([], $this->resolver()->getChoiceGroups($licencie));
        self::assertSame('Veste', $this->resolver()->resolveDotation($licencie)[0]['stockItem']->getNom());
    }

    public function testRenouvellementGardeLeChoixEntreLesDeuxOptions(): void
    {
        [$season, $cat] = $this->kitVesteOuTshirt();
        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $groupes = $this->resolver()->getChoiceGroups($licencie);
        self::assertCount(1, $groupes);
        self::assertSame('Dotation', $groupes[0]['groupe']);
        self::assertCount(2, $groupes[0]['options']);
    }

    public function testNatureInconnueEstTraiteeCommeUnRenouvellement(): void
    {
        [$season, $cat] = $this->kitVesteOuTshirt();
        // Nature null : la colonne « Nature » manquait à l'import. Il ne faut surtout pas
        // le priver de son choix sur une donnée absente.
        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: null);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertCount(2, $this->resolver()->getChoiceGroups($licencie)[0]['options']);
    }

    public function testDirigeantEstTraiteCommeUnRenouvellement(): void
    {
        [$season, , $modele] = $this->kitVesteOuTshirt();

        $dirigeant = (new Dirigeant())->setNom('MARTIN')->setPrenom('Paul')->setSeason($season);
        $dirigeant->setTailleHaut('M');
        $this->em->persist($dirigeant);
        $this->em->flush();

        $affectation = (new \App\Entity\DotationAffectation())->setSeason($season)->setModele($modele)->setDirigeant($dirigeant);
        $this->em->persist($affectation);

        /** @var Dirigeant $dirigeant */
        $dirigeant = $this->reload($dirigeant);

        self::assertCount(2, $this->resolver()->getChoiceGroups($dirigeant)[0]['options']);
    }

    public function testChoixStockeDevenuNonEligibleRetombeSurLOptionEligible(): void
    {
        [$season, $cat, , $tshirt] = $this->kitVesteOuTshirt();

        // Le licencié avait choisi le t-shirt en tant que renouvellement…
        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        $this->em->flush();
        $this->setReponsesFormulaire($licencie, ['Dotation' => $tshirt->getId()]);

        // …puis l'admin corrige sa nature en « nouvelle demande ».
        $licencie->setNatureLicence(NatureLicence::NOUVELLE_DEMANDE)->setNatureManuelle(true);
        $this->em->flush();

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertSame(
            'Veste',
            $this->resolver()->resolveDotation($licencie)[0]['stockItem']->getNom(),
            'Un choix devenu non éligible retombe sur la première option éligible.',
        );
    }

    public function testGroupeSansAucuneOptionEligibleNeProduitRien(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $fixe = $this->makeItem('Short', StockItemVetementType::BAS);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $fixe, 1);
        $this->addLigne($modele, $this->makeItem('Veste', StockItemVetementType::HAUT), 1, 'Dotation', DotationEligibilite::RENOUVELLEMENTS);
        $this->addLigne($modele, $this->makeItem('Sweat', StockItemVetementType::HAUT), 1, 'Dotation', DotationEligibilite::RENOUVELLEMENTS);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::NOUVELLE_DEMANDE);
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $lignes = $this->resolver()->resolveDotation($licencie);
        self::assertCount(1, $lignes, 'Seule la ligne fixe reste due.');
        self::assertSame('Short', $lignes[0]['stockItem']->getNom());
    }

    public function testLigneFixeReserveeAuxNouveauxNEstPasDueAUnRenouvellement(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $modele = $this->makeModele($season, 'Dotation 2026');
        // Un sac de bienvenue n'a de sens que pour une première licence au club.
        $this->addLigne($modele, $this->makeItem('Sac de bienvenue', null), 1, null, DotationEligibilite::NOUVEAUX);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: NatureLicence::RENOUVELLEMENT);
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertSame([], $this->resolver()->resolveDotation($licencie));
    }
}

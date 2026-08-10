<?php declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Service\Dirigeant\DirigeantDossierCompletion;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Complétude du dossier dirigeant : informations propres au dirigeant, plus tous les
 * documents que la saison lui demande. Un dirigeant-joueur signe bien deux règlements
 * distincts — celui des joueurs dans son dossier licencié ne l'exonère de rien ici.
 */
final class DirigeantDossierCompletionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentFixtures $fixtures;
    private DirigeantDossierCompletion $completion;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new DocumentFixtures($this->em);
        $this->completion = self::getContainer()->get(DirigeantDossierCompletion::class);
    }

    public function testDirigeantNonLieDoitSignerLeReglement(): void
    {
        $season = $this->makeSeason();

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setVolontaireTransport(false)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true);

        $document = $this->fixtures->documentDirigeant($season);

        $this->em->persist($season);
        $this->em->persist($dirigeant);
        $this->em->flush();

        // Tout le reste est rempli mais le règlement manque → dossier incomplet.
        self::assertTrue($dirigeant->isBaseFormComplete());
        self::assertFalse($this->completion->isComplete($dirigeant));

        $this->fixtures->signerParDirigeant($document, $dirigeant);
        $this->em->flush();

        self::assertTrue($this->completion->isComplete($dirigeant));
    }

    public function testDirigeantJoueurDoitSignerMemeSiSonLicencieASigne(): void
    {
        [$dirigeant, $document] = $this->makeDirigeantLieAuLicencie(licencieSigne: true);

        // Le dossier joueur signé ne couvre que le règlement des joueurs.
        self::assertFalse($this->completion->isComplete($dirigeant));

        // Une fois le règlement dirigeants signé : taille/photo viennent du
        // licencié, transport renseigné → dossier complet.
        $this->fixtures->signerParDirigeant($document, $dirigeant);
        $this->em->flush();

        self::assertTrue($this->completion->isComplete($dirigeant));
    }

    public function testDirigeantJoueurAvecLicencieNonSigneDoitSigner(): void
    {
        [$dirigeant] = $this->makeDirigeantLieAuLicencie(licencieSigne: false);

        self::assertFalse($this->completion->isComplete($dirigeant));
    }

    public function testAjouterUnDocumentEnCoursDeSaisonRendLeDossierIncomplet(): void
    {
        $season = $this->makeSeason();

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setVolontaireTransport(false)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true);

        $reglement = $this->fixtures->documentDirigeant($season);

        $this->em->persist($season);
        $this->em->persist($dirigeant);
        $this->em->flush();

        $this->fixtures->signerParDirigeant($reglement, $dirigeant);
        $this->em->flush();

        self::assertTrue($this->completion->isComplete($dirigeant));

        // Le club ajoute une charte visant tout le monde : le dossier redevient incomplet
        // sans qu'aucune donnée du dirigeant n'ait été touchée.
        $this->fixtures->documentDirigeant(
            $season,
            code: 'charte_communication',
            titre: 'Charte d\'engagement — Communication',
            sortOrder: 20,
        );
        $this->em->flush();

        self::assertFalse($this->completion->isComplete($dirigeant));
    }

    /** @return array{0: Dirigeant, 1: \App\Entity\DocumentSignable} */
    private function makeDirigeantLieAuLicencie(bool $licencieSigne): array
    {
        $season = $this->makeSeason();
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setLicencie($licencie)
            ->setVolontaireTransport(false);

        $reglementJoueurs = $this->fixtures->documentLicencie($season);
        $reglementDirigeants = $this->fixtures->documentDirigeant($season);

        $this->em->persist($season);
        $this->em->persist($category);
        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->persist($dirigeant);
        $this->em->flush();

        if ($licencieSigne) {
            $this->fixtures->signerParLicencie($reglementJoueurs, $licencie);
            $this->em->flush();
        }

        return [$dirigeant, $reglementDirigeants];
    }

    private function makeSeason(): Season
    {
        return (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
    }
}

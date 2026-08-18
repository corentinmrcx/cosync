<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Transaction;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;
use App\Tests\Support\DocumentFixtures;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Un licencié déjà passé par tout le parcours revient dans l'export FootClubs — au statut
 * « Attestation licence créée », puisque sa licence est validée. L'import le retrouve et le met
 * à jour : il ne doit toucher que l'identité FFF.
 *
 * C'est le scénario du ré-import de routine en cours de saison, et le plus coûteux à rater : le
 * club a la signature manuscrite, l'encaissement et la dotation en face. Aucun de ces faits ne se
 * reconstitue. L'uuid, en particulier, est la clé du lien public déjà distribué : le régénérer
 * couperait le licencié de son formulaire en pleine saisie.
 */
final class ImportPreserveDonneesClubTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type', 'Statut',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville',
    ];

    private const NUM_LICENCE = '2544553590';

    public function testUnReimportNeToucheAAucuneDonneeClub(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        $licencie = $this->licencieAvecUneHistoire($season);

        $uuidAvant = (string) $licencie->getUuid();
        $lienEnvoyeAvant = $licencie->getLinkSentAt();
        $boutiqueAvant = $licencie->getBoutiqueAnnonceeAt();
        $equipeAvant = $licencie->getTeam()?->getName();
        $rempliAvant = $licencie->getDossierClub()?->getFormCompletedAt();

        $result = $this->service(ImportService::class)->importFromXlsx($this->exportFootClubs(), $season);

        self::assertSame(0, $result->created, 'la fiche est retrouvée, pas recréée');
        self::assertSame(1, $result->updated);

        $this->em->clear();
        /** @var LicencieRepository $repo */
        $repo = $this->service(LicencieRepository::class);
        $apres = $repo->findByNumLicence(self::NUM_LICENCE, $season);
        self::assertNotNull($apres);

        // — L'identité publique et le contact déjà pris —
        self::assertSame($uuidAvant, (string) $apres->getUuid(), 'l\'uuid du lien public ne bouge pas');
        self::assertEquals($lienEnvoyeAvant, $apres->getLinkSentAt(), 'la date d\'envoi du lien fait foi, elle ne se réécrit pas');
        self::assertEquals($boutiqueAvant, $apres->getBoutiqueAnnonceeAt(), 'l\'annonce boutique ne se rejoue pas');
        self::assertSame($equipeAvant, $apres->getTeam()?->getName(), 'l\'équipe affectée à la main survit');

        // — Le dossier club —
        $dossier = $apres->getDossierClub();
        self::assertNotNull($dossier);
        self::assertSame(LicenceStatus::VALIDATED, $dossier->getStatus(), 'un licencié validé ne redevient pas « importé »');
        self::assertEquals($rempliAvant, $dossier->getFormCompletedAt());
        self::assertSame('L', $dossier->getTailleHaut());
        self::assertSame('M', $dossier->getTailleBas());
        self::assertSame('44', $dossier->getPointure());
        self::assertTrue($dossier->getAutorisationPhoto());

        // — Ce que le club a encaissé et fait signer —
        self::assertCount(1, $this->em->getRepository(Transaction::class)->findBy(['licencie' => $apres]));
        self::assertCount(1, $this->em->getRepository(\App\Entity\DocumentSignature::class)->findBy(['licencie' => $apres]));

        // — Et l'identité FFF, elle, suit bien l'export —
        self::assertSame('DJAFRI', $apres->getNom());
        self::assertSame('sofiane.djafri@example.fr', $apres->getEmail(), 'l\'email non verrouillé suit FootClubs');
    }

    /**
     * Le second import ne doit pas non plus rejouer les effets de bord d'une création : ni
     * réaffectation d'équipe automatique, ni nouveau dossier club à côté de l'ancien.
     */
    public function testUnReimportNeCreePasUnSecondDossierClub(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        $this->licencieAvecUneHistoire($season);

        $service = $this->service(ImportService::class);
        $service->importFromXlsx($this->exportFootClubs(), $season);
        $service->importFromXlsx($this->exportFootClubs(), $season);

        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(Licencie::class)->findBy(['season' => $season]));
        self::assertCount(1, $this->em->getRepository(DossierClub::class)->findAll());
    }

    /** L'export tel que FootClubs le rend une fois la licence validée. */
    private function exportFootClubs(): UploadedFile
    {
        return $this->makeXlsx(self::HEADERS, [[
            'DJAFRI', 'SOFIANE', self::NUM_LICENCE, 'Libre / Senior', 'Joueur', 'Attestation licence créée',
            '02/08/1998', 'sofiane.djafri@example.fr', '0662374711', '135 avenue Daniel Simonnot', '51000', 'CHALONS',
        ]]);
    }

    /** Une fiche arrivée au bout du parcours : formulaire signé, paiement encaissé, kit à remettre. */
    private function licencieAvecUneHistoire(Season $season): Licencie
    {
        $team = (new Team())->setName('Séniors 1')->setSeason($season);
        $this->em->persist($team);

        $licencie = (new Licencie())
            ->setNom('DJAFRI')
            ->setPrenom('Sofiane')
            ->setNumLicence(self::NUM_LICENCE)
            ->setDateNaissance(new \DateTimeImmutable('1998-08-02'))
            ->setCategory($this->makeCategory('SENIOR'))
            ->setSeason($season)
            ->setTeam($team)
            ->setEmail('ancien@example.fr')
            ->setLinkSentAt(new \DateTimeImmutable('2026-07-01'))
            ->setBoutiqueAnnonceeAt(new \DateTimeImmutable('2026-07-15'));
        $this->em->persist($licencie);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::VALIDATED)
            ->setFormCompletedAt(new \DateTimeImmutable('2026-07-05'))
            ->setTailleHaut('L')
            ->setTailleBas('M')
            ->setPointure('44')
            ->setAutorisationPhoto(true);
        $this->em->persist($dossier);
        $licencie->setDossierClub($dossier);

        $this->em->persist(
            (new Transaction())
                ->setLicencie($licencie)
                ->setSeason($season)
                ->setMode(PaymentMode::CHEQUE)
                ->setMontant('85.00')
                ->setDatePaiement(new \DateTimeImmutable('2026-07-06')),
        );

        $documents = new DocumentFixtures($this->em);
        $documents->signerParLicencie($documents->documentLicencie($season), $licencie);

        $this->em->flush();

        self::assertTrue(Uuid::isValid((string) $licencie->getUuid()));

        return $licencie;
    }
}

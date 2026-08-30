<?php declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\DTO\AttestationPaiementData;
use App\Entity\AttestationPaiement;
use App\Entity\Category;
use App\Entity\ClubSettings;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Enum\Civilite;
use App\Enum\LicenceStatus;
use App\Enum\LienParente;
use App\Enum\PaymentMode;
use App\Service\Effectif\SuppressionFicheService;
use App\Service\Payment\AttestationPaiementService;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Une attestation de paiement part chez un employeur : elle ne doit jamais affirmer un
 * versement que la comptabilité du club ne montre pas, ni continuer d'évoluer après avoir
 * été remise. Ces deux règles sont ce que verrouillent ces tests.
 */
final class AttestationPaiementServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AttestationPaiementService $service;

    /** @var list<string> chemins des PDF produits, effacés en fin de test */
    private array $pdfsProduits = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(AttestationPaiementService::class);

        $this->configurerLeClub();
    }

    protected function tearDown(): void
    {
        // Émettre écrit un PDF sur le disque : sans ce ménage, la suite en accumule
        // un par exécution dans var/test-pdfs.
        foreach ($this->pdfsProduits as $chemin) {
            @unlink($chemin);
        }

        $this->pdfsProduits = [];

        parent::tearDown();
    }

    /* ─────────────────────────── Le verrou ─────────────────────────── */

    public function testSansAucunPaiementIlNYARienAAttester(): void
    {
        $licencie = $this->licencie(cotisation: 120);

        self::assertFalse($this->service->peutEmettre($licencie));
        self::assertStringContainsString('Aucun paiement', (string) $this->service->motifBlocage($licencie));
    }

    public function testUneLicenceAMoitiePayeeNEstPasAttestable(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-08-10');

        self::assertFalse($this->service->peutEmettre($licencie));
        self::assertStringContainsString('pas soldée', (string) $this->service->motifBlocage($licencie));
    }

    /**
     * Le piège que le verrou doit attraper : « Valider quand même » passe le dossier en
     * VALIDATED sans qu'un centime soit entré. Un verrou posé sur le statut aurait émis
     * une attestation affirmant un paiement qui n'a jamais eu lieu.
     */
    public function testUneLicenceValideeALaMainSansPaiementNEstPasAttestable(): void
    {
        $licencie = $this->licencie(cotisation: 120);

        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::VALIDATED);
        $licencie->setDossierClub($dossier);
        $this->em->persist($dossier);
        $this->em->flush();

        self::assertFalse($this->service->peutEmettre($licencie));
    }

    public function testUneLicenceSoldeeEstAttestable(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        self::assertNull($this->service->motifBlocage($licencie));
    }

    public function testSansSignataireConfigureAucuneAttestationNEstEmise(): void
    {
        $club = self::getContainer()->get(ClubSettingsService::class)->get();
        $club->setSignataireNom(null);
        $this->em->flush();

        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        self::assertStringContainsString('signataire', (string) $this->service->motifBlocage($licencie));
    }

    /* ──────────────────── Ce que le document affirme ──────────────────── */

    public function testLeMontantEstLaSommeDesPaiementsEtNeSeSaisitPas(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '70.00', PaymentMode::CHEQUE, '2026-08-10');
        $this->paiement($licencie, '50.00', PaymentMode::ESPECES, '2026-08-25');

        $attestation = $this->service->composer($licencie, $this->data());

        self::assertSame('120.00', $attestation->getMontant());
        self::assertSame('cent vingt euros', $attestation->getMontantEnLettres());
    }

    /** Un paiement fractionné a plusieurs modes : le document les nomme tous. */
    public function testLesModesEmployesSontTousRepris(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '70.00', PaymentMode::CHEQUE, '2026-08-10');
        $this->paiement($licencie, '50.00', PaymentMode::ESPECES, '2026-08-25');

        $attestation = $this->service->composer($licencie, $this->data());

        self::assertSame('Chèque, Espèces', $attestation->getModesLabel());
    }

    /** Deux chèques du même mode ne doivent pas donner « Chèque, Chèque ». */
    public function testUnModeEmployeDeuxFoisNEstNommeQuUneFois(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-08-10');
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-09-10');

        self::assertSame('Chèque', $this->service->composer($licencie, $this->data())->getModesLabel());
    }

    /** C'est le versement qui solde la licence qui date le paiement, pas le premier. */
    public function testLaDateRetenueEstCelleDuDernierVersement(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-08-10');
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-09-15');

        $attestation = $this->service->composer($licencie, $this->data());

        self::assertSame('2026-09-15', $attestation->getDatePaiement()->format('Y-m-d'));
    }

    /* ──────────────────────── Le figeage ──────────────────────── */

    /**
     * Le cœur du modèle : le document remis à un employeur ne bouge plus. Un paiement
     * corrigé ou supprimé après coup ne doit rien changer à ce qu'il affirme.
     */
    public function testUnPaiementSupprimeApresCoupNAltereRienDeLAttestation(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $paiement = $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        $attestation = $this->emettre($licencie);
        $id = $attestation->getId();

        $this->em->remove($paiement);
        $this->em->flush();
        $this->em->clear();

        $relue = $this->em->find(AttestationPaiement::class, $id);

        self::assertNotNull($relue);
        self::assertSame('120.00', $relue->getMontant());
        self::assertSame('cent vingt euros', $relue->getMontantEnLettres());
        self::assertSame('Carte bancaire', $relue->getModesLabel());
        self::assertCount(0, $relue->getTransactions(), 'Seul le rapprochement disparaît.');
    }

    /** Le club change de trésorier : les attestations déjà émises gardent leur signataire. */
    public function testLeSignataireEstRecopieEtNonReference(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        $attestation = $this->emettre($licencie);
        $id = $attestation->getId();

        $club = self::getContainer()->get(ClubSettingsService::class)->get();
        $club->setSignataireNom('Bernard Dupuis')->setSignataireQualite('président');
        $club->setSignataireCivilite(Civilite::M);
        $this->em->flush();
        $this->em->clear();

        $relue = $this->em->find(AttestationPaiement::class, $id);

        self::assertNotNull($relue);
        self::assertSame('Claudine Moreaux', $relue->getSignataireNom());
        self::assertSame('trésorière', $relue->getSignataireQualite());
    }

    public function testEmettreSurUneLicenceNonSoldeeEstRefuse(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $this->paiement($licencie, '60.00', PaymentMode::CHEQUE, '2026-08-10');

        $this->expectException(\LogicException::class);
        $this->service->emettre($licencie, $this->data());
    }

    /**
     * Une attestation suppose un paiement — mais celui-ci peut être supprimé ensuite. Sans
     * ce garde-fou, la fiche paraissait vierge à l'analyse et sa suppression butait sur la
     * clé étrangère, l'admin n'ayant que le message générique du rattrapage.
     */
    public function testUneFicheAyantUneAttestationNeSeSupprimePlus(): void
    {
        $licencie = $this->licencie(cotisation: 120);
        $paiement = $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        $this->emettre($licencie);

        $this->em->remove($paiement);
        $this->em->flush();

        $analyse = self::getContainer()->get(SuppressionFicheService::class)->analyser($licencie);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('attestation', (string) $analyse->motifRefus);
    }

    /* ──────────────────────── Le pré-remplissage ──────────────────────── */

    public function testUnAdulteEstSonPropreDestinataire(): void
    {
        $licencie = $this->licencie(cotisation: 120, codeCategorie: 'SENIOR');

        $data = $this->service->prefill($licencie);

        self::assertSame('Thomas', $data->destinatairePrenom);
        self::assertSame(LienParente::LUI_MEME, $data->lienParente);
    }

    /** FootClubs ne donne le nom d'aucun parent : le champ reste à saisir. */
    public function testPourUnJeuneLeDestinataireResteASaisir(): void
    {
        $licencie = $this->licencie(cotisation: 120, codeCategorie: 'U13');

        $data = $this->service->prefill($licencie);

        self::assertSame('', $data->destinatairePrenom);
        self::assertSame(LienParente::SON_ENFANT, $data->lienParente);
    }

    /** La deuxième attestation d'une saison vise presque toujours le même payeur. */
    public function testLeDestinataireDeLaPrecedenteEstRepropose(): void
    {
        $licencie = $this->licencie(cotisation: 120, codeCategorie: 'U13');
        $this->paiement($licencie, '120.00', PaymentMode::CB_ONLINE, '2026-08-10');

        $this->emettre($licencie);

        $data = $this->service->prefill($licencie);

        self::assertSame('Ericka', $data->destinatairePrenom);
        self::assertSame('Marcoux', $data->destinataireNom);
        self::assertSame(LienParente::SON_FILS, $data->lienParente);
    }

    /* ──────────────────────────── Fixtures ──────────────────────────── */

    /** Émet et retient le chemin du PDF, que tearDown effacera. */
    private function emettre(Licencie $licencie): AttestationPaiement
    {
        $attestation = $this->service->emettre($licencie, $this->data());
        $this->pdfsProduits[] = (string) $attestation->getDrivePath();

        return $attestation;
    }

    private function data(): AttestationPaiementData
    {
        return new AttestationPaiementData(
            destinataireCivilite: Civilite::MME,
            destinatairePrenom: 'Ericka',
            destinataireNom: 'Marcoux',
            lienParente: LienParente::SON_FILS,
            email: null, // aucun envoi : ces tests portent sur le document, pas sur le mail
        );
    }

    private function configurerLeClub(): void
    {
        $club = self::getContainer()->get(ClubSettingsService::class)->get();
        $club
            ->setAssociationNom('Foyer de Soudron')
            ->setAssociationVille('Soudron')
            ->setSignataireCivilite(Civilite::MME)
            ->setSignataireNom('Claudine Moreaux')
            ->setSignataireQualite('trésorière');
        $this->em->flush();

        self::assertInstanceOf(ClubSettings::class, $club);
    }

    private function licencie(int $cotisation, string $codeCategorie = 'U13'): Licencie
    {
        $season = (new Season())
            ->setLabel('2026-2027')
            ->setCotisationDefaut($cotisation);
        $this->em->persist($season);

        $category = (new Category())
            ->setCode($codeCategorie)
            ->setLabel($codeCategorie)
            ->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom('MARCOUX')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2013-04-02'))
            ->setCategory($category)
            ->setSeason($season);
        $this->em->persist($licencie);
        $this->em->flush();

        return $licencie;
    }

    private function paiement(Licencie $licencie, string $montant, PaymentMode $mode, string $date): Transaction
    {
        $transaction = (new Transaction())
            ->setLicencie($licencie)
            ->setSeason($licencie->getSeason())
            ->setMontant($montant)
            ->setMode($mode)
            ->setDatePaiement(new \DateTimeImmutable($date));

        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }
}

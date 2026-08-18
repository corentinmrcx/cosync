<?php declare(strict_types=1);

namespace App\Tests\Service\Effectif;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Service\Effectif\SuppressionFicheService;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le seul geste irréversible du mode édition. Ces tests fixent la ligne de partage : une fiche
 * vierge part, une fiche qui a une histoire dans le club reste — et le motif du refus est dit.
 *
 * Un faux positif ici, c'est une signature manuscrite ou un encaissement perdu sans retour
 * arrière possible.
 */
final class SuppressionFicheServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SuppressionFicheService $service;
    private DocumentFixtures $documents;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(SuppressionFicheService::class);
        $this->documents = new DocumentFixtures($this->em);
    }

    public function testUneFicheViergeEstSupprimable(): void
    {
        $licencie = $this->makeLicencie();

        self::assertTrue($this->service->analyser($licencie)->supprimable);
    }

    public function testUnLienEnvoyeInterditLaSuppression(): void
    {
        $licencie = $this->makeLicencie();
        $licencie->setLinkSentAt(new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $analyse = $this->service->analyser($licencie);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('12/08/2026', (string) $analyse->motifRefus);
    }

    public function testUnFormulaireRempliInterditLaSuppression(): void
    {
        $licencie = $this->makeLicencie();
        $licencie->getDossierClub()?->setFormCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertFalse($this->service->analyser($licencie)->supprimable);
    }

    public function testUnDossierSortiDeLImportInterditLaSuppression(): void
    {
        $licencie = $this->makeLicencie();
        $licencie->getDossierClub()?->setStatus(LicenceStatus::VALIDATED);
        $this->em->flush();

        $analyse = $this->service->analyser($licencie);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('Validé', (string) $analyse->motifRefus);
    }

    public function testUnPaiementEnregistreInterditLaSuppression(): void
    {
        $licencie = $this->makeLicencie();
        $this->em->persist(
            (new Transaction())
                ->setLicencie($licencie)
                ->setSeason($licencie->getSeason())
                ->setMode(PaymentMode::CHEQUE)
                ->setMontant('85.00')
                ->setDatePaiement(new \DateTimeImmutable()),
        );
        $this->em->flush();

        $analyse = $this->service->analyser($licencie);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('paiement', (string) $analyse->motifRefus);
    }

    public function testUnDocumentSigneInterditLaSuppression(): void
    {
        $licencie = $this->makeLicencie();
        $document = $this->documents->documentLicencie($licencie->getSeason());
        $this->documents->signerParLicencie($document, $licencie);
        $this->em->flush();

        $analyse = $this->service->analyser($licencie);

        self::assertFalse($analyse->supprimable);
        self::assertStringContainsString('signé', (string) $analyse->motifRefus);
    }

    public function testUnDirigeantViergeEstSupprimableMaisPasUnDirigeantRelance(): void
    {
        $season = $this->makeSeason();
        $vierge = $this->makeDirigeant($season);
        $relance = $this->makeDirigeant($season)->setLinkSentAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertTrue($this->service->analyser($vierge)->supprimable);
        self::assertFalse($this->service->analyser($relance)->supprimable);
    }

    /**
     * Le cas réel du ménage : un lot mêlant des fiches importées par erreur et des fiches
     * légitimes. Tout ou rien ferait échouer le ménage entier sur une seule fiche protégée.
     */
    public function testUnLotSupprimeLesFichesViergesEtEpargneLesAutres(): void
    {
        $season = $this->makeSeason();
        $aSupprimer = $this->makeLicencie($season, 'FANTOME');
        $aGarder = $this->makeLicencie($season, 'VRAI');
        $aGarder->setLinkSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $resultat = $this->service->supprimerLot([$aSupprimer, $aGarder]);

        self::assertSame(1, $resultat->supprimees);
        self::assertCount(1, $resultat->refusees);
        self::assertStringContainsString('VRAI', $resultat->refusees[0]);

        $this->em->clear();
        $repo = self::getContainer()->get(LicencieRepository::class);
        self::assertCount(1, $repo->findBySeason($season), 'seule la fiche vierge est partie');
    }

    /** Le dossier club n'existe que par son licencié : il ne doit pas rester orphelin. */
    public function testLaSuppressionEmporteLeDossierClub(): void
    {
        $licencie = $this->makeLicencie();
        $dossierId = $licencie->getDossierClub()?->getId();

        $this->service->supprimerLot([$licencie]);
        $this->em->clear();

        self::assertNotNull($dossierId);
        self::assertNull($this->em->getRepository(DossierClub::class)->find($dossierId));
    }

    /* ── Fabriques ── */

    private function makeSeason(): Season
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($season);

        return $season;
    }

    private function makeLicencie(?Season $season = null, string $nom = 'DUPONT'): Licencie
    {
        static $n = 0;
        ++$n;

        $season ??= $this->makeSeason();
        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas' . $n)
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($season);
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::IMPORTED);
        $this->em->persist($dossier);
        $licencie->setDossierClub($dossier);

        $this->em->flush();

        return $licencie;
    }

    private function makeDirigeant(Season $season): Dirigeant
    {
        static $n = 0;
        ++$n;

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin' . $n)
            ->setSeason($season);
        $this->em->persist($dirigeant);

        return $dirigeant;
    }
}

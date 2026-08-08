<?php declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\DossierClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sélection des dossiers à réconcilier par app:helloasso:sync-paiements.
 *
 * C'est le filet de sécurité derrière le webhook : si une notification n'arrive
 * jamais, c'est cette requête qui décide si l'encaissement sera rattrapé ou perdu.
 * Un dossier écarté à tort, c'est le club qui a l'argent sans que la licence passe
 * en validée — et personne pour s'en apercevoir.
 */
final class DossierClubReconciliationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUnDossierSansIntentionNEstPasReconcilie(): void
    {
        $this->makeDossier(intentId: null);

        self::assertSame([], $this->aReconcilier());
    }

    public function testUnDossierAvecIntentionNonValideeEstReconcilie(): void
    {
        $dossier = $this->makeDossier();

        self::assertContains($dossier->getId(), $this->aReconcilier());
    }

    public function testUnDossierDejaValideNEstPlusReconcilie(): void
    {
        $dossier = $this->makeDossier(statut: LicenceStatus::VALIDATED);

        self::assertNotContains($dossier->getId(), $this->aReconcilier());
    }

    /**
     * Le cas que l'ancienne requête ratait : elle écartait tout dossier portant déjà
     * une transaction en ligne. Un licencié dont le premier paiement a échoué
     * partiellement et qui en relance un second n'était jamais rattrapé.
     */
    public function testUnSecondPaiementEnLigneResteReconcilie(): void
    {
        $dossier = $this->makeDossier();

        // Premier encaissement HelloAsso, insuffisant : la licence n'est pas validée.
        $transaction = (new Transaction())
            ->setLicencie($dossier->getLicencie())
            ->setSeason($dossier->getLicencie()->getSeason())
            ->setMode(PaymentMode::CB_ONLINE)
            ->setMontant('40.00')
            ->setDatePaiement(new \DateTimeImmutable())
            ->setExternalPaymentId('11111');
        $this->em->persist($transaction);
        $this->em->flush();

        self::assertContains(
            $dossier->getId(),
            $this->aReconcilier(),
            'Un dossier non soldé doit rester réconciliable même s\'il porte déjà une transaction en ligne.',
        );
    }

    /** Une intention abandonnée depuis des mois ne doit plus interroger l'API à chaque passage. */
    public function testUneIntentionTropAncienneNEstPlusInterrogee(): void
    {
        $dossier = $this->makeDossier(demarreeLe: new \DateTimeImmutable('-100 days'));

        self::assertNotContains($dossier->getId(), $this->aReconcilier());
    }

    public function testUneIntentionRecenteResteInterrogee(): void
    {
        $dossier = $this->makeDossier(demarreeLe: new \DateTimeImmutable('-3 days'));

        self::assertContains($dossier->getId(), $this->aReconcilier());
    }

    /** Les dossiers antérieurs à l'ajout de la colonne n'ont pas de date : ils restent traités. */
    public function testUneIntentionSansDateResteInterrogee(): void
    {
        $dossier = $this->makeDossier(demarreeLe: null);

        self::assertContains($dossier->getId(), $this->aReconcilier());
    }

    /* ── Outils ── */

    /** @return int[] */
    private function aReconcilier(): array
    {
        $this->em->clear();

        return array_map(
            static fn (DossierClub $d): int => $d->getId(),
            self::getContainer()->get(DossierClubRepository::class)->findWithPendingHelloAssoPayment(),
        );
    }

    private function makeDossier(
        ?string $intentId = 'intent-123',
        LicenceStatus $statut = LicenceStatus::FORM_COMPLETED,
        ?\DateTimeImmutable $demarreeLe = new \DateTimeImmutable('-1 hour'),
    ): DossierClub {
        static $n = 0;
        ++$n;

        $season   = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas' . $n)
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($statut)
            ->setFormCompletedAt(new \DateTimeImmutable())
            ->setHelloassoCheckoutIntentId($intentId)
            ->setHelloassoCheckoutStartedAt($intentId === null ? null : $demarreeLe);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $dossier;
    }
}

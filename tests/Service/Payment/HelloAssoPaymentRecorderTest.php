<?php declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Service\Payment\CotisationResolver;
use App\Service\Licencie\PaiementService;
use App\Service\Payment\HelloAssoClient;
use App\Service\Payment\HelloAssoPaymentRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Conteneur et base réels, seul le dialogue HTTP avec HelloAsso est simulé :
 * c'est exactement la frontière qu'on veut vérifier, puisque la règle du projet est
 * qu'aucune licence n'est validée sans encaissement confirmé par l'API.
 */
final class HelloAssoPaymentRecorderTest extends KernelTestCase
{
    private const BASE_URL = 'https://api.helloasso-sandbox.com';
    private const INTENT_ID = '987654';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeLicencie(int $cotisation = 85): Licencie
    {
        static $n = 0;
        ++$n;

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut($cotisation);
        $this->em->persist($season);

        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas' . $n)
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($season);
        $this->em->persist($licencie);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable())
            ->setHelloassoCheckoutIntentId(self::INTENT_ID);
        $this->em->persist($dossier);

        $this->em->flush();
        // Relecture pour que le côté inverse (licencie.dossierClub) soit hydraté par Doctrine,
        // comme il l'est dans une requête réelle.
        $this->em->clear();

        return self::getContainer()->get(LicencieRepository::class)->findByUuid($licencie->getUuid());
    }

    /** @param array<string, mixed> $intentPayload */
    private function makeRecorder(array $intentPayload): HelloAssoPaymentRecorder
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($intentPayload): MockResponse {
            $body = str_ends_with($url, '/oauth2/token')
                ? ['access_token' => 'jwt', 'token_type' => 'bearer', 'expires_in' => 1800]
                : $intentPayload;

            return new MockResponse(
                json_encode($body, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URL);

        $client = new HelloAssoClient($httpClient, new ArrayAdapter(), self::BASE_URL, 'id', 'secret', 'slug');

        return new HelloAssoPaymentRecorder(
            $client,
            self::getContainer()->get(LicencieRepository::class),
            self::getContainer()->get(TransactionRepository::class),
            self::getContainer()->get(PaiementService::class),
            self::getContainer()->get(CotisationResolver::class),
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function intentAvecPaiement(Licencie $licencie, string $state, int $paymentId = 55555): array
    {
        return [
            'id' => self::INTENT_ID,
            'metadata' => ['licencie_uuid' => (string) $licencie->getUuid()],
            'order' => [
                'id' => 4242,
                'amount' => ['total' => 8500],
                'payments' => [[
                    'id' => $paymentId,
                    'amount' => 8500,
                    'date' => '2026-08-05T10:12:00+02:00',
                    'state' => $state,
                ]],
            ],
        ];
    }

    public function testUnPaiementAutoriseCreeLaTransactionEtValideLaLicence(): void
    {
        $licencie = $this->makeLicencie();
        $recorder = $this->makeRecorder($this->intentAvecPaiement($licencie, 'Authorized'));

        self::assertTrue($recorder->recordFromCheckoutIntent(self::INTENT_ID));

        $transactions = self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie);
        self::assertCount(1, $transactions);
        self::assertSame(PaymentMode::CB_ONLINE, $transactions[0]->getMode());
        self::assertSame('85.00', $transactions[0]->getMontant());
        self::assertSame('HA-55555', $transactions[0]->getReference());
        self::assertSame('55555', $transactions[0]->getExternalPaymentId());
        self::assertNull($transactions[0]->getConfirmedBy(), 'Aucun dirigeant ne confirme un encaissement en ligne.');
        self::assertSame(LicenceStatus::VALIDATED, $licencie->getDossierClub()->getStatus());
    }

    public function testUnMemePaiementNotifieDeuxFoisNEstEncaisseQuUneFois(): void
    {
        $licencie = $this->makeLicencie();
        $recorder = $this->makeRecorder($this->intentAvecPaiement($licencie, 'Authorized'));

        self::assertTrue($recorder->recordFromCheckoutIntent(self::INTENT_ID));
        self::assertFalse($recorder->recordFromCheckoutIntent(self::INTENT_ID));

        self::assertCount(1, self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie));
    }

    /**
     * Le cœur de la règle métier : tant que HelloAsso n'a pas autorisé le paiement,
     * rien n'est encaissé et la licence reste en attente.
     */
    public function testUnPaiementNonAutoriseNeCreeRienEtNeValidePas(): void
    {
        $licencie = $this->makeLicencie();
        $recorder = $this->makeRecorder($this->intentAvecPaiement($licencie, 'Pending'));

        self::assertFalse($recorder->recordFromCheckoutIntent(self::INTENT_ID));

        self::assertSame([], self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie));
        self::assertSame(LicenceStatus::FORM_COMPLETED, $licencie->getDossierClub()->getStatus());
    }

    public function testUneIntentionSansCommandeNeCreeRien(): void
    {
        $licencie = $this->makeLicencie();
        $recorder = $this->makeRecorder(['id' => self::INTENT_ID, 'metadata' => ['licencie_uuid' => (string) $licencie->getUuid()]]);

        self::assertFalse($recorder->recordFromCheckoutIntent(self::INTENT_ID));

        self::assertSame([], self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie));
        self::assertSame(LicenceStatus::FORM_COMPLETED, $licencie->getDossierClub()->getStatus());
    }

    public function testUnUuidInconnuNeCreeRien(): void
    {
        $this->makeLicencie();
        $recorder = $this->makeRecorder([
            'id' => self::INTENT_ID,
            'metadata' => ['licencie_uuid' => '00000000-0000-4000-8000-000000000000'],
            'order' => ['payments' => [['id' => 1, 'amount' => 8500, 'state' => 'Authorized']]],
        ]);

        self::assertFalse($recorder->recordFromCheckoutIntent(self::INTENT_ID));
    }

    /**
     * Encaissement plus faible que la cotisation (85 € débités pour 120 € dus) : seule la somme
     * réellement reçue est créditée, et la licence reste en attente. C'est le garde-fou contre
     * une licence validée pour de l'argent jamais reçu.
     */
    public function testUnPaiementInferieurALaCotisationNeCrediteQueLeReelEtNeValidePas(): void
    {
        $licencie = $this->makeLicencie(120);
        $recorder = $this->makeRecorder($this->intentAvecPaiement($licencie, 'Authorized'));

        self::assertTrue($recorder->recordFromCheckoutIntent(self::INTENT_ID));

        $transactions = self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie);
        self::assertSame('85.00', $transactions[0]->getMontant());
        self::assertSame(LicenceStatus::FORM_COMPLETED, $licencie->getDossierClub()->getStatus());
    }

    /** La contribution volontaire versée à HelloAsso ne doit jamais être créditée au club. */
    public function testLaContributionVolontaireNEstPasCrediteeAuClub(): void
    {
        $licencie = $this->makeLicencie(85);

        $intent = $this->intentAvecPaiement($licencie, 'Authorized');
        $intent['order']['payments'][0]['amount'] = 9350; // 85 € de cotisation + 8,50 € de contribution

        self::assertTrue($this->makeRecorder($intent)->recordFromCheckoutIntent(self::INTENT_ID));

        $transactions = self::getContainer()->get(TransactionRepository::class)->findByLicencie($licencie);
        self::assertSame('85.00', $transactions[0]->getMontant());
        self::assertSame(LicenceStatus::VALIDATED, $licencie->getDossierClub()->getStatus());
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\AttestationCle;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\DocumentSignature;
use App\Entity\Season;
use App\Enum\CleMouvementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours public de l'attestation de remise de clés. Le lien porte l'attestation
 * d'une saison, pas la personne : un lien de l'an dernier ne vaut jamais pour
 * l'engagement de cette année, et la signature n'effleure pas le dossier dirigeant.
 */
final class AttestationCleFormControllerTest extends WebTestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testLeFormulaireAfficheCeQueLaPersonneAtteste(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation(nbCles: 2, remiseLe: '2026-01-10');

        $crawler = $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('reconnais avoir reçu', $html);
        self::assertStringContainsString('2 clés', $html);
        self::assertStringContainsString('10/01/2026', $html);
        self::assertStringContainsString('Ne pas prêter cette clé', $html, 'L\'engagement du club est affiché.');
        self::assertStringContainsString('2025-2026', $html, 'L\'engagement est daté d\'une saison.');
    }

    /**
     * Sans engagement rédigé par le club, la case ne doit pas faire accepter des
     * conditions que la page n'affiche nulle part.
     */
    public function testSansEngagementRedigeLaCaseNeParleQueDExactitude(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation(engagement: null);

        $crawler = $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        $html = $crawler->html();
        self::assertStringContainsString('J\'atteste l\'exactitude de ce qui précède.', $html);
        self::assertStringNotContainsString('respecter ces conditions', $html);
        self::assertStringContainsString('reconnais avoir reçu', $html, 'Le document reste affiché.');
    }

    public function testLeRecepisseNImposePasDeLectureAvantDeCocher(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();

        $crawler = $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        self::assertCount(0, $crawler->filter('[x-ref="reglementEl"]'), 'Pas de zone de lecture imposée.');
        self::assertStringNotContainsString('Faites défiler', $crawler->html());
    }

    public function testLaSignatureEnregistreLeDocumentEtConsommeLeLien(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation(nbCles: 1, remiseLe: '2026-01-10');
        $uuid = (string) $attestation->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => self::SIGNATURE,
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid . '/confirmation');

        $rechargée = $this->reload($uuid);

        self::assertTrue($rechargée->estSignee());
        self::assertStringStartsWith('/', (string) $rechargée->getDrivePath(), 'Chemin local avant upload Drive.');
        self::assertFalse($rechargée->isTokenValid(), 'Le lien doit être consommé.');
        self::assertSame(1, $rechargée->getNbCles(), 'Le nombre attesté est figé depuis le registre.');
        self::assertSame('2026-01-10', $rechargée->getRemiseLe()->format('Y-m-d'));
    }

    public function testLaSignatureNeTouchePasAuxDocumentsSignables(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();
        $uuid = (string) $attestation->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => self::SIGNATURE,
        ]);

        // L'attestation de clés suit son propre circuit : aucune signature de document
        // signable ne doit lui être rattachée.
        self::assertSame(0, $this->countDocumentSignatures());
    }

    public function testUneSignatureManquanteEstRejetee(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();
        $uuid = (string) $attestation->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid);
        self::assertFalse($this->reload($uuid)->estSignee());
    }

    public function testUneSignatureMalformeeEstRejetee(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();
        $uuid = (string) $attestation->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => 'javascript:alert(1)',
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid);
        self::assertFalse($this->reload($uuid)->estSignee());
    }

    public function testUnLienExpireNAfficheAucunFormulaire(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation(tokenExpiresAt: new \DateTimeImmutable('-1 day'));

        $crawler = $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'));
        self::assertStringContainsString('Lien expiré', $crawler->html());
    }

    public function testUnSignataireEstRedirigeVersLaConfirmation(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();
        $this->signer($attestation, '2026-01-11');

        $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        self::assertResponseRedirects('/attestation-cle/' . $attestation->getUuid() . '/confirmation');
    }

    /**
     * Une attestation signée n'est jamais rouverte : la demande suivante ouvre une
     * ligne à part, et les deux PDF font foi à leur date.
     */
    public function testUnLienDejaSigneNeSertPasARessignerUnNouveauNombreDeCles(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation(nbCles: 1, remiseLe: '2026-01-10');
        $this->signer($attestation, '2026-01-11');
        $uuid = (string) $attestation->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => 'peu-importe',
            'signature_data' => self::SIGNATURE,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            '2026-01-11',
            $this->reload($uuid)->getSignedAt()->format('Y-m-d'),
            'La signature d\'origine n\'est pas écrasée.',
        );
    }

    public function testUnUuidInconnuAfficheLaPageExpiree(): void
    {
        $client = static::createClient();

        $client->request('GET', '/attestation-cle/' . Uuid::v4());

        self::assertResponseIsSuccessful();
    }

    public function testLaRouteEstPubliqueSansAuthentification(): void
    {
        $client = static::createClient();
        $attestation = $this->createAttestation();

        $client->request('GET', '/attestation-cle/' . $attestation->getUuid());

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('Location');
    }

    /* ── Fabriques ── */

    private function createAttestation(
        int $nbCles = 1,
        string $remiseLe = '2026-01-10',
        ?\DateTimeImmutable $tokenExpiresAt = null,
        ?string $engagement = '<p>Ne pas prêter cette clé et la restituer sur demande.</p>',
    ): AttestationCle {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        static $n = 0;
        ++$n;

        $season = (new Season())
            ->setLabel('2025-2026')
            ->setCotisationDefaut(85)
            ->setAttestationCleText($engagement);
        $em->persist($season);

        $detenteur = (new Detenteur())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setEmail(sprintf('attestation%d@example.com', $n));
        $em->persist($detenteur);

        $em->persist(
            (new CleMouvement())
                ->setDetenteur($detenteur)
                ->setType(CleMouvementType::REMISE)
                ->setQuantite($nbCles)
                ->setDateMouvement(new \DateTimeImmutable($remiseLe)),
        );

        $attestation = (new AttestationCle())
            ->setDetenteur($detenteur)
            ->setSeason($season)
            ->setDemandeEnvoyeeLe(new \DateTimeImmutable())
            ->setTokenExpiresAt($tokenExpiresAt ?? new \DateTimeImmutable('+30 days'));

        $em->persist($attestation);
        $em->flush();

        return $attestation;
    }

    private function signer(AttestationCle $attestation, string $date): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $attestation
            ->setSignedAt(new \DateTimeImmutable($date))
            ->setDrivePath('drive-id-123')
            ->setTokenExpiresAt(null);

        $em->flush();
    }

    private function tokenFor(KernelBrowser $client, string $uuid): string
    {
        $crawler = $client->request('GET', '/attestation-cle/' . $uuid);

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function countDocumentSignatures(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)
            ->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(DocumentSignature::class, 's')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function reload(string $uuid): AttestationCle
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(AttestationCle::class)->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}

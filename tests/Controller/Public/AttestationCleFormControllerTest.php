<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\DocumentSignature;
use App\Entity\CleMouvement;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\CleMouvementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours public de l'attestation de remise de clés. Vérifie que le récépissé
 * reprend bien le registre, et qu'il reste étanche au parcours « dossier dirigeant » :
 * les deux tokens et les deux jeux de dates ne doivent jamais se contaminer.
 */
final class AttestationCleFormControllerTest extends WebTestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgo=';

    public function testLeFormulaireAfficheCeQueLaPersonneAtteste(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 2, '2026-01-10');

        $crawler = $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('reconnais avoir reçu', $html);
        self::assertStringContainsString('2 clés', $html);
        self::assertStringContainsString('10/01/2026', $html);
        self::assertStringContainsString('Ne pas prêter cette clé', $html, 'L\'engagement du club est affiché.');
    }

    public function testLeRecepisseNImposePasDeLectureAvantDeCocher(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');

        $crawler = $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertCount(0, $crawler->filter('[x-ref="reglementEl"]'), 'Pas de zone de lecture imposée.');
        self::assertStringNotContainsString('Faites défiler', $crawler->html());
    }

    public function testLaSignatureEnregistreLeDocumentEtConsommeLeLien(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $uuid = (string) $dirigeant->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => self::SIGNATURE,
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid . '/confirmation');

        $rechargé = $this->reload($uuid);

        self::assertNotNull($rechargé->getAttestationCleSignedAt());
        self::assertTrue($rechargé->hasSignedAttestationCle());
        self::assertStringStartsWith('/', (string) $rechargé->getAttestationCleSignePath(), 'Chemin local avant upload Drive.');
        self::assertFalse($rechargé->isAttestationCleTokenValid(), 'Le lien doit être consommé.');
    }

    public function testLaSignatureNeTouchePasAuDossierDirigeant(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $uuid = (string) $dirigeant->getUuid();

        $formTokenAvant = $this->reload($uuid)->getFormTokenExpiresAt();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => self::SIGNATURE,
        ]);

        $apres = $this->reload($uuid);

        self::assertEquals($formTokenAvant, $apres->getFormTokenExpiresAt(), 'Le token du dossier dirigeant est indépendant.');
        self::assertNull($apres->getFormCompletedAt());
        // L'attestation de clés suit son propre circuit : aucune signature de document
        // signable ne doit lui être rattachée.
        self::assertSame(0, $this->countDocumentSignatures());
    }

    public function testUneSignatureManquanteEstRejetee(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $uuid = (string) $dirigeant->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid);
        self::assertNull($this->reload($uuid)->getAttestationCleSignedAt());
    }

    public function testUneSignatureMalformeeEstRejetee(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $uuid = (string) $dirigeant->getUuid();

        $client->request('POST', '/attestation-cle/' . $uuid, [
            '_token' => $this->tokenFor($client, $uuid),
            'signature_data' => 'javascript:alert(1)',
        ]);

        self::assertResponseRedirects('/attestation-cle/' . $uuid);
        self::assertNull($this->reload($uuid)->getAttestationCleSignedAt());
    }

    public function testUnLienExpireNAfficheAucunFormulaire(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant(tokenExpiresAt: new \DateTimeImmutable('-1 day'));

        $crawler = $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'));
        self::assertStringContainsString('Lien expiré', $crawler->html());
    }

    public function testUnSignataireAJourEstRedirigeVersLaConfirmation(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $this->marquerSignee($dirigeant, '2026-01-11');

        $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertResponseRedirects('/attestation-cle/' . $dirigeant->getUuid() . '/confirmation');
    }

    public function testUneCleRemiseApresSignatureRouvreLeFormulaire(): void
    {
        $client = static::createClient();
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');
        $this->marquerSignee($dirigeant, '2026-01-11');
        $this->remise($dirigeant, 1, '2026-03-01');

        $crawler = $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertResponseIsSuccessful('Une attestation dépassée doit pouvoir être resignée.');
        self::assertStringContainsString('2 clés', $crawler->html(), 'Le récépissé reprend le nouveau total.');
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
        $dirigeant = $this->createDirigeant();
        $this->remise($dirigeant, 1, '2026-01-10');

        $client->request('GET', '/attestation-cle/' . $dirigeant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('Location');
    }

    /* ── Fabriques ── */

    private function createDirigeant(?\DateTimeImmutable $tokenExpiresAt = null): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        static $n = 0;
        ++$n;

        $season = (new Season())
            ->setLabel('2025-2026')
            ->setCotisationDefaut(85)
            ->setAttestationCleText('<p>Ne pas prêter cette clé et la restituer sur demande.</p>');
        $em->persist($season);

        $dirigeant = (new Dirigeant())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setEmail(sprintf('attestation%d@example.com', $n))
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'))
            ->setAttestationCleTokenExpiresAt($tokenExpiresAt ?? new \DateTimeImmutable('+30 days'));

        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
    }

    private function remise(Dirigeant $dirigeant, int $quantite, string $date): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $em->persist(
            (new CleMouvement())
                ->setDirigeant($dirigeant)
                ->setSeason($dirigeant->getSeason())
                ->setType(CleMouvementType::REMISE)
                ->setQuantite($quantite)
                ->setDateMouvement(new \DateTimeImmutable($date)),
        );
        $em->flush();
    }

    private function marquerSignee(Dirigeant $dirigeant, string $date): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $dirigeant
            ->setAttestationCleSignePath('drive-id-123')
            ->setAttestationCleSignedAt(new \DateTimeImmutable($date))
            ->setAttestationCleTokenExpiresAt(new \DateTimeImmutable('+30 days'));
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
            ->from(\App\Entity\DocumentSignature::class, 's')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function reload(string $uuid): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Dirigeant::class, Uuid::fromString($uuid));
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\Licencie;
use App\Entity\Season;
use App\Service\Payment\HelloAssoClient;
use App\Service\Payment\HelloAssoException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HelloAssoClientTest extends TestCase
{
    private const BASE_URL = 'https://api.helloasso-sandbox.com';

    private function makeLicencie(int $cotisation = 85): Licencie
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut($cotisation);

        return (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setEmail('thomas@example.test')
            ->setSeason($season);
    }

    private function tokenResponse(): MockResponse
    {
        return new MockResponse(
            json_encode(['access_token' => 'jwt-abc', 'token_type' => 'bearer', 'expires_in' => 1800], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /** @param MockResponse[] $responses */
    private function makeClient(array $responses, ?ArrayAdapter $cache = null): HelloAssoClient
    {
        return new HelloAssoClient(
            new MockHttpClient($responses, self::BASE_URL),
            $cache ?? new ArrayAdapter(),
            self::BASE_URL,
            'client-id',
            'client-secret',
            'foyer-de-soudron',
        );
    }

    public function testCreationDIntentionEnvoieLeMontantEnCentimesEtLUuidEnMetadonnee(): void
    {
        $envoye = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$envoye): MockResponse {
            if (str_ends_with($url, '/oauth2/token')) {
                return $this->tokenResponse();
            }

            $envoye = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true), 'headers' => $options['headers']];

            return new MockResponse(
                json_encode(['id' => 123456, 'redirectUrl' => 'https://checkout.helloasso.test/123456'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URL);

        $client   = new HelloAssoClient($httpClient, new ArrayAdapter(), self::BASE_URL, 'client-id', 'client-secret', 'foyer-de-soudron');
        $licencie = $this->makeLicencie();

        $intent = $client->createCheckoutIntent($licencie, 85, 'https://cosync.test/retour', 'https://cosync.test/erreur');

        self::assertSame('123456', $intent->id);
        self::assertSame('https://checkout.helloasso.test/123456', $intent->redirectUrl);

        self::assertSame('POST', $envoye['method']);
        self::assertSame(self::BASE_URL . '/v5/organizations/foyer-de-soudron/checkout-intents', $envoye['url']);
        self::assertSame(8500, $envoye['body']['totalAmount']);
        self::assertSame(8500, $envoye['body']['initialAmount']);
        self::assertFalse($envoye['body']['containsDonation']);
        self::assertSame('https://cosync.test/retour', $envoye['body']['returnUrl']);
        self::assertSame('https://cosync.test/erreur', $envoye['body']['errorUrl']);
        self::assertSame('https://cosync.test/erreur', $envoye['body']['backUrl']);
        self::assertSame((string) $licencie->getUuid(), $envoye['body']['metadata']['licencie_uuid']);
        self::assertSame('Thomas', $envoye['body']['payer']['firstName']);
        self::assertSame('thomas@example.test', $envoye['body']['payer']['email']);
        self::assertContains('Authorization: Bearer jwt-abc', $envoye['headers']);
    }

    public function testLeLibelleResteSousLaLimiteDeLApi(): void
    {
        $envoye = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$envoye): MockResponse {
            if (str_ends_with($url, '/oauth2/token')) {
                return $this->tokenResponse();
            }

            $envoye = json_decode($options['body'], true);

            return new MockResponse(
                json_encode(['id' => 1, 'redirectUrl' => 'https://checkout.helloasso.test/1'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URL);

        $licencie = $this->makeLicencie()->setNom(str_repeat('A', 300));

        (new HelloAssoClient($httpClient, new ArrayAdapter(), self::BASE_URL, 'id', 'secret', 'slug'))
            ->createCheckoutIntent($licencie, 85, 'https://cosync.test/retour', 'https://cosync.test/erreur');

        self::assertLessThanOrEqual(250, mb_strlen($envoye['itemName']));
    }

    public function testLeJetonEstReutiliseEntreDeuxAppels(): void
    {
        $appelsToken = 0;

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$appelsToken): MockResponse {
            if (str_ends_with($url, '/oauth2/token')) {
                ++$appelsToken;

                return $this->tokenResponse();
            }

            return new MockResponse(
                json_encode(['id' => 1, 'redirectUrl' => 'https://checkout.helloasso.test/1'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URL);

        $client   = new HelloAssoClient($httpClient, new ArrayAdapter(), self::BASE_URL, 'id', 'secret', 'slug');
        $licencie = $this->makeLicencie();

        $client->createCheckoutIntent($licencie, 85, 'https://cosync.test/r', 'https://cosync.test/e');
        $client->createCheckoutIntent($licencie, 85, 'https://cosync.test/r', 'https://cosync.test/e');

        self::assertSame(1, $appelsToken, 'Le jeton mis en cache doit être réutilisé.');
    }

    public function testUneErreurHttpRemonteEnHelloAssoException(): void
    {
        $client = $this->makeClient([
            $this->tokenResponse(),
            new MockResponse('{"message":"Bad Request"}', ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        $this->expectException(HelloAssoException::class);

        $client->createCheckoutIntent($this->makeLicencie(), 85, 'https://cosync.test/r', 'https://cosync.test/e');
    }

    public function testSansConfigurationAucunAppelNEstTente(): void
    {
        $client = new HelloAssoClient(new MockHttpClient([]), new ArrayAdapter(), self::BASE_URL, '', '', '');

        self::assertFalse($client->isConfigured());

        $this->expectException(HelloAssoException::class);

        $client->getCheckoutIntent('123');
    }
}

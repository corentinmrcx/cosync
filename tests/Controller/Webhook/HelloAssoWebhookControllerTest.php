<?php declare(strict_types=1);

namespace App\Tests\Controller\Webhook;

use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le webhook est public : il doit encaisser les notifications sans jamais tomber en erreur,
 * et sans jamais créer de paiement sur la seule foi du contenu reçu.
 */
final class HelloAssoWebhookControllerTest extends WebTestCase
{
    /** @param array<string, string> $headers */
    private function post(KernelBrowser $client, string $body, array $headers = []): void
    {
        $client->request('POST', '/webhook/helloasso', [], [], array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            $headers,
        ), $body);
    }

    public function testLaRouteEstAccessibleSansAuthentification(): void
    {
        $client = static::createClient();
        $this->post($client, '{"eventType":"Order"}');

        self::assertResponseIsSuccessful();
    }

    public function testUnEvenementNonPaiementEstIgnoreSansErreur(): void
    {
        $client = static::createClient();
        $this->post($client, '{"eventType":"Organization","data":{}}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], static::getContainer()->get(TransactionRepository::class)->findAll());
    }

    public function testUnCorpsIllisibleNeFaitPasTomberLApplication(): void
    {
        $client = static::createClient();
        $this->post($client, 'ceci n\'est pas du json');

        self::assertResponseStatusCodeSame(200, 'Toujours 200 : une erreur de notre côté ne doit pas déclencher les rejeux HelloAsso.');
    }

    /**
     * Une notification de paiement forgée ne crée rien : l'état réel est relu auprès de
     * l'API HelloAsso, injoignable ici faute de configuration.
     */
    public function testUneNotificationDePaiementForgeeNeCreeAucuneTransaction(): void
    {
        $client = static::createClient();

        $this->post($client, json_encode([
            'eventType' => 'Payment',
            'data' => [
                'id' => 999,
                'state' => 'Authorized',
                'order' => ['checkoutIntentId' => 123456],
            ],
            'metadata' => ['licencie_uuid' => '00000000-0000-4000-8000-000000000000'],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], static::getContainer()->get(TransactionRepository::class)->findAll());
    }

    public function testUneSignatureInvalideEstRejetee(): void
    {
        // Le secret n'est pas configuré en test : la vérification est alors volontairement passante,
        // la sécurité reposant sur la revérification auprès de l'API. On documente ce contrat ici.
        $verifier = static::getContainer()->get(\App\Service\Payment\HelloAssoWebhookVerifier::class);

        self::assertTrue($verifier->isTrusted('{}', null), 'Sans secret configuré, la notification passe.');

        $avecSecret = new \App\Service\Payment\HelloAssoWebhookVerifier('un-secret');
        self::assertFalse($avecSecret->isTrusted('{}', 'signature-bidon'));
        self::assertFalse($avecSecret->isTrusted('{}', null));
        self::assertTrue($avecSecret->isTrusted('{}', hash_hmac('sha256', '{}', 'un-secret')));
    }
}

<?php declare(strict_types=1);

namespace App\Service\Payment;

use App\DTO\HelloAssoCheckoutIntent;
use App\Entity\Licencie;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as HttpClientHttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Seule classe qui parle à l'API HelloAsso. Elle ne décide rien : elle authentifie,
 * crée une intention de paiement et relit l'état d'une intention existante.
 *
 * Aucune donnée bancaire ne transite ici : le payeur saisit sa carte sur le domaine HelloAsso.
 */
final class HelloAssoClient
{
    private const TOKEN_CACHE_KEY = 'helloasso.access_token';
    private const ITEM_NAME_MAX_LENGTH = 250;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(HELLOASSO_API_BASE_URL)%')] private readonly string $baseUrl,
        #[Autowire('%env(HELLOASSO_CLIENT_ID)%')] private readonly string $clientId,
        #[Autowire('%env(HELLOASSO_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(HELLOASSO_ORG_SLUG)%')] private readonly string $organizationSlug,
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->organizationSlug !== '';
    }

    /**
     * Crée une intention de paiement et retourne l'URL HelloAsso vers laquelle rediriger le licencié.
     *
     * Attention : HelloAsso n'accepte que des URLs de retour en HTTPS et rejette tout http://
     * (« Le champ BackUrl est invalide »). En local, tester derrière un tunnel HTTPS.
     *
     * @param int $montantEuros montant de la cotisation en euros (converti en centimes pour HelloAsso)
     *
     * @throws HelloAssoException
     */
    public function createCheckoutIntent(
        Licencie $licencie,
        int $montantEuros,
        string $returnUrl,
        string $errorUrl,
    ): HelloAssoCheckoutIntent {
        $centimes = $montantEuros * 100;

        $body = [
            'totalAmount'      => $centimes,
            'initialAmount'    => $centimes,
            'itemName'         => $this->buildItemName($licencie),
            'backUrl'          => $errorUrl,
            'errorUrl'         => $errorUrl,
            'returnUrl'        => $returnUrl,
            'containsDonation' => false,
            'payer'            => $this->buildPayer($licencie),
            'metadata'         => ['licencie_uuid' => (string) $licencie->getUuid()],
        ];

        $data = $this->request('POST', sprintf('/v5/organizations/%s/checkout-intents', $this->organizationSlug), [
            'json' => $body,
        ]);

        $id          = $data['id'] ?? null;
        $redirectUrl = $data['redirectUrl'] ?? null;

        if ($id === null || !is_string($redirectUrl) || $redirectUrl === '') {
            throw new HelloAssoException('Réponse HelloAsso inattendue : id ou redirectUrl manquant.');
        }

        return new HelloAssoCheckoutIntent((string) $id, $redirectUrl);
    }

    /**
     * Relit l'état réel d'une intention de paiement. C'est la seule source de vérité :
     * ni les paramètres de la returnUrl ni le corps d'une notification ne sont dignes de confiance.
     *
     * @return array<string, mixed>
     *
     * @throws HelloAssoException
     */
    public function getCheckoutIntent(string $checkoutIntentId): array
    {
        return $this->request('GET', sprintf(
            '/v5/organizations/%s/checkout-intents/%s',
            $this->organizationSlug,
            rawurlencode($checkoutIntentId),
        ));
    }

    /**
     * Jeton OAuth2 client_credentials, mis en cache jusqu'à un peu avant son expiration.
     */
    private function accessToken(): string
    {
        $item = $this->cache->getItem(self::TOKEN_CACHE_KEY);

        if ($item->isHit()) {
            return (string) $item->get();
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/oauth2/token', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);
            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new HelloAssoException('Authentification HelloAsso impossible : ' . $e->getMessage(), 0, $e);
        }

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HelloAssoException('Authentification HelloAsso : access_token absent de la réponse.');
        }

        // Marge de 60 s pour ne jamais présenter un jeton expiré entre deux requêtes.
        $ttl = max(60, (int) ($data['expires_in'] ?? 1800) - 60);
        $item->set($token)->expiresAfter($ttl);
        $this->cache->save($item);

        return $token;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws HelloAssoException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new HelloAssoException('HelloAsso n\'est pas configuré (variables d\'environnement manquantes).');
        }

        $options['headers'] = ['Authorization' => 'Bearer ' . $this->accessToken()];

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new HelloAssoException(
                sprintf('Appel HelloAsso %s %s en échec : %s%s', $method, $path, $e->getMessage(), $this->detailsErreur($e)),
                0,
                $e,
            );
        }
    }

    /**
     * Corps de la réponse d'erreur renvoyé par HelloAsso : c'est lui qui nomme le champ fautif
     * (« Le champ BackUrl est invalide », etc.). Destiné aux logs uniquement — le licencié ne
     * voit jamais qu'un message générique, pour ne rien exposer de l'intégration en production.
     */
    private function detailsErreur(HttpExceptionInterface $e): string
    {
        if (!$e instanceof HttpClientHttpExceptionInterface) {
            return '';
        }

        $corps = trim($e->getResponse()->getContent(false));

        return $corps === '' ? '' : ' — réponse : ' . mb_substr($corps, 0, 500);
    }

    private function buildItemName(Licencie $licencie): string
    {
        $name = sprintf(
            'Cotisation — Saison %s — %s %s',
            $licencie->getSeason()->getLabel(),
            $licencie->getNom(),
            $licencie->getPrenom(),
        );

        return mb_substr($name, 0, self::ITEM_NAME_MAX_LENGTH);
    }

    /**
     * Pré-remplit le payeur pour épargner une saisie au licencié : moins de champs, moins d'abandons.
     *
     * @return array<string, string>
     */
    private function buildPayer(Licencie $licencie): array
    {
        $payer = [
            'firstName' => $licencie->getPrenom(),
            'lastName'  => $licencie->getNom(),
        ];

        $email = $licencie->getEmail();
        if ($email !== null && $email !== '') {
            $payer['email'] = $email;
        }

        return $payer;
    }
}

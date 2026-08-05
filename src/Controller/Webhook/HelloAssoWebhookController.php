<?php declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Repository\LicencieRepository;
use App\Service\Payment\HelloAssoPaymentRecorder;
use App\Service\Payment\HelloAssoWebhookVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Notifications HelloAsso. Route publique : elle n'est jamais crue sur parole.
 *
 * Le payload sert uniquement à savoir quelle intention de paiement regarder ;
 * HelloAssoPaymentRecorder relit ensuite son état réel auprès de l'API HelloAsso avant
 * d'enregistrer le moindre encaissement.
 *
 * Hors signature invalide, la réponse est toujours 200 : une erreur métier de notre côté ne doit
 * pas déclencher les rejeux HelloAsso (jusqu'à 16 tentatives sur 48 h). Les erreurs sont loggées.
 */
class HelloAssoWebhookController extends AbstractController
{
    #[Route('/webhook/helloasso', name: 'webhook_helloasso', methods: ['POST'])]
    public function __invoke(
        Request $request,
        HelloAssoWebhookVerifier $verifier,
        HelloAssoPaymentRecorder $recorder,
        LicencieRepository $licencieRepo,
        LoggerInterface $logger,
    ): Response {
        $raw = $request->getContent();

        if (!$verifier->isTrusted($raw, $request->headers->get('x-ha-signature'))) {
            $logger->warning('HelloAsso : notification rejetée, signature invalide.');

            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

            if (($payload['eventType'] ?? null) !== 'Payment') {
                return new Response('', Response::HTTP_OK);
            }

            $intentId = $this->resolveCheckoutIntentId($payload, $licencieRepo);

            if ($intentId === null) {
                $logger->info('HelloAsso : notification de paiement sans intention identifiable, ignorée.');

                return new Response('', Response::HTTP_OK);
            }

            $recorder->recordFromCheckoutIntent($intentId);
        } catch (\Throwable $e) {
            $logger->error('HelloAsso : traitement de la notification en échec : {message}', [
                'message' => $e->getMessage(),
                'payload' => $raw,
            ]);
        }

        return new Response('', Response::HTTP_OK);
    }

    /**
     * L'intention est portée par la commande ; à défaut on la retrouve via le licencié
     * transmis dans les métadonnées de l'intention créée par CoSync.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveCheckoutIntentId(array $payload, LicencieRepository $licencieRepo): ?string
    {
        $fromOrder = $payload['data']['order']['checkoutIntentId'] ?? null;
        if (is_int($fromOrder) || (is_string($fromOrder) && $fromOrder !== '')) {
            return (string) $fromOrder;
        }

        $uuid = $payload['metadata']['licencie_uuid'] ?? null;
        if (!is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $licencieRepo->findByUuid(Uuid::fromString($uuid))
            ?->getDossierClub()
            ?->getHelloassoCheckoutIntentId();
    }
}

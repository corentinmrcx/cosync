<?php declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Licencie;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Service\Payment\CotisationResolver;
use App\Service\Licencie\PaiementService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistre un encaissement HelloAsso, et lui seul.
 *
 * Point d'entrée unique appelé par la page de retour, par le webhook et par la commande de
 * réconciliation. Trois garanties, dans cet ordre :
 *
 * 1. L'état du paiement est relu directement chez HelloAsso avec notre propre jeton. Ni les
 *    paramètres de la returnUrl ni le corps d'une notification ne déclenchent quoi que ce soit :
 *    une licence n'est jamais marquée payée sans encaissement authentifié.
 * 2. Le traitement est idempotent — un même paiement notifié plusieurs fois ne crée qu'une
 *    transaction (vérification en amont + contrainte d'unicité en base pour les cas concurrents).
 * 3. Le passage éventuel à VALIDATED reste la règle existante du club (total payé ≥ cotisation),
 *    déléguée à LicencieService.
 */
final class HelloAssoPaymentRecorder
{
    private const AUTHORIZED_STATE = 'Authorized';

    public function __construct(
        private readonly HelloAssoClient $client,
        private readonly LicencieRepository $licencieRepo,
        private readonly TransactionRepository $transactionRepo,
        private readonly PaiementService $paiementService,
        private readonly CotisationResolver $cotisationResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return bool true si un paiement a été nouvellement enregistré
     *
     * @throws HelloAssoException si l'API HelloAsso est injoignable
     */
    public function recordFromCheckoutIntent(string $checkoutIntentId): bool
    {
        $intent = $this->client->getCheckoutIntent($checkoutIntentId);

        $payment = $this->firstAuthorizedPayment($intent);
        if ($payment === null) {
            $this->logger->info('HelloAsso : aucun paiement autorisé sur l\'intention {id}, rien à enregistrer.', [
                'id' => $checkoutIntentId,
            ]);

            return false;
        }

        $licencie = $this->resolveLicencie($intent);
        if ($licencie === null) {
            $this->logger->warning('HelloAsso : licencié introuvable pour l\'intention {id}, paiement non rattaché.', [
                'id' => $checkoutIntentId,
            ]);

            return false;
        }

        $externalPaymentId = (string) $payment['id'];
        if ($this->transactionRepo->findOneByExternalPaymentId($externalPaymentId) !== null) {
            return false;
        }

        $montant = $this->montantRevenantAuClub($payment, $licencie, $checkoutIntentId);

        try {
            $this->paiementService->enregistrer(
                licencie: $licencie,
                mode: PaymentMode::CB_ONLINE,
                montant: $montant,
                reference: 'HA-' . $externalPaymentId,
                note: 'Paiement en ligne HelloAsso',
                datePaiement: $this->paymentDate($payment),
                confirmedBy: null,
                season: $licencie->getSeason(),
                externalPaymentId: $externalPaymentId,
            );
        } catch (UniqueConstraintViolationException) {
            // La page de retour et le webhook ont traité le même paiement en parallèle : la base a
            // tranché, l'encaissement est déjà enregistré une fois.
            return false;
        }

        $this->logger->info('HelloAsso : paiement {payment} enregistré pour {licencie}.', [
            'payment' => $externalPaymentId,
            'licencie' => (string) $licencie->getUuid(),
        ]);

        return true;
    }

    /**
     * Premier paiement réellement autorisé de la commande. Tout autre état (en attente, refusé,
     * remboursé) ne vaut pas encaissement.
     *
     * @param array<string, mixed> $intent
     *
     * @return array<string, mixed>|null
     */
    private function firstAuthorizedPayment(array $intent): ?array
    {
        $payments = $intent['order']['payments'] ?? null;
        if (!is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            if (is_array($payment)
                && ($payment['state'] ?? null) === self::AUTHORIZED_STATE
                && isset($payment['id'])
            ) {
                return $payment;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $intent */
    private function resolveLicencie(array $intent): ?Licencie
    {
        $uuid = $intent['metadata']['licencie_uuid'] ?? null;
        if (!is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->licencieRepo->findByUuid(Uuid::fromString($uuid));
    }

    /** @param array<string, mixed> $payment */
    private function paymentDate(array $payment): \DateTimeImmutable
    {
        $date = $payment['date'] ?? null;
        if (is_string($date)) {
            try {
                return new \DateTimeImmutable($date);
            } catch (\Exception) {
                // Date illisible : on retombe sur maintenant plutôt que de refuser l'encaissement.
            }
        }

        return new \DateTimeImmutable();
    }

    /**
     * Ce qui revient réellement au club, borné des deux côtés :
     *
     * - jamais plus que la cotisation : le montant débité inclut la contribution volontaire du
     *   payeur à HelloAsso, qui ne revient pas au club ;
     * - jamais plus que ce qui a effectivement été débité : un encaissement plus faible
     *   qu'attendu ne doit pas créditer la cotisation entière, sans quoi une licence serait
     *   validée pour de l'argent jamais reçu.
     *
     * @param array<string, mixed> $payment
     */
    private function montantRevenantAuClub(array $payment, Licencie $licencie, string $checkoutIntentId): float
    {
        $cotisation = (float) $this->cotisationResolver->resolve($licencie);

        $encaisseCentimes = $payment['amount'] ?? null;
        if (!is_numeric($encaisseCentimes)) {
            // Montant illisible : on s'en tient au plus prudent, rien n'est crédité au-delà du réel.
            $this->logger->warning('HelloAsso : montant absent du paiement sur l\'intention {id}, cotisation retenue par défaut.', [
                'id' => $checkoutIntentId,
            ]);

            return $cotisation;
        }

        $encaisse = (int) $encaisseCentimes / 100;

        if ($encaisse < $cotisation) {
            $this->logger->warning('HelloAsso : encaissement de {encaisse} € inférieur à la cotisation attendue de {attendu} € sur l\'intention {id}. La licence restera en attente.', [
                'encaisse' => $encaisse,
                'attendu' => $cotisation,
                'id' => $checkoutIntentId,
            ]);
        }

        return min($cotisation, $encaisse);
    }
}

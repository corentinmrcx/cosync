<?php declare(strict_types=1);

namespace App\Service\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vérifie la signature d'une notification HelloAsso (header "x-ha-signature",
 * HMAC-SHA256 hexadécimal du corps brut).
 *
 * HelloAsso ne fournit une signatureKey qu'aux comptes partenaires : un compte association
 * n'en a pas. Quand le secret n'est pas configuré, la notification est acceptée ici —
 * la sécurité réelle ne repose pas sur cette signature mais sur HelloAssoPaymentRecorder,
 * qui relit l'état du paiement chez HelloAsso avant d'enregistrer quoi que ce soit.
 * Une fausse notification ne peut donc rien créer.
 */
final class HelloAssoWebhookVerifier
{
    public function __construct(
        #[Autowire('%env(HELLOASSO_WEBHOOK_SECRET)%')] private readonly string $secret,
    ) {}

    public function isTrusted(string $rawBody, ?string $signatureHeader): bool
    {
        if ($this->secret === '') {
            return true;
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        return hash_equals($expected, strtolower(trim($signatureHeader)));
    }
}

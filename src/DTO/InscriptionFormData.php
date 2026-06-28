<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\PaymentMode;

final class InscriptionFormData
{
    public function __construct(
        public readonly string $tailleHaut,
        public readonly string $tailleBas,
        public readonly string $pointure,
        public readonly bool $autorisationPhoto,
        public readonly ?bool $autorisationTransportDirigeants,
        public readonly ?bool $autorisationTransportParents,
        public readonly ?bool $autorisationAccident,
        public readonly ?bool $volontaireTransport,
        public readonly string $signatureData,
        /** @var PaymentMode[] */
        public readonly array $paymentIntentions,
        public readonly ?AttestationTransportData $attestationTransport = null,
    ) {}
}

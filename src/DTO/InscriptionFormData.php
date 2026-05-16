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
        public readonly string $signatureData,
        public readonly PaymentMode $paymentIntention,
    ) {}
}

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
        /**
         * Signatures des documents à signer, indexées par id de DocumentSignable.
         *
         * @var array<int, string> data URL base64 de la signature manuscrite
         */
        public readonly array $documentSignatures,
        /** @var PaymentMode[] */
        public readonly array $paymentIntentions,
        /** Nature du paiement quand « Autre » est retenu (tickets MSA, coupon sport…) */
        public readonly ?string $paymentAutrePrecision = null,
        public readonly ?AttestationTransportData $attestationTransport = null,
        /** @var array<string, int> { groupeChoix: stockItemId } */
        public readonly array $dotationChoix = [],
        /** @var array<string, string> { clé de personnalisation: texte à floquer } */
        public readonly array $dotationPersonnalisation = [],
    ) {}
}

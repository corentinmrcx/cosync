<?php declare(strict_types=1);

namespace App\DTO;

final class AttestationCleSignatureData
{
    public function __construct(
        /** data-URI base64 du canvas de signature */
        public readonly string $signatureData,
    ) {}
}

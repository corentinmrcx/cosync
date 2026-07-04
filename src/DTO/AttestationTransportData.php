<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Données d'attestation de transport bénévole.
 * Utilisé par le formulaire licencié et (à venir) le formulaire dirigeant.
 * Ces données ne sont PAS persistées en base — seul l'ID Drive du PDF est stocké.
 */
final class AttestationTransportData
{
    public function __construct(
        public readonly string $nomConducteur,
        public readonly string $prenomConducteur,
        public readonly string $numPermis,
        public readonly string $assuranceNomAdresse,
        public readonly ?\DateTimeImmutable $dateCT,
        public readonly bool $vehiculeNeuf,
        public readonly bool $engagementPris,
        public readonly string $signatureData,
    ) {}
}

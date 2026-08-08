<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Ligne du récapitulatif destiné à la mairie. Ne porte aucune image de signature :
 * celle-ci ne figure que sur l'attestation individuelle archivée sur Drive.
 */
final class AttestationCleRecapRow
{
    public function __construct(
        public readonly string $nom,
        public readonly string $prenom,
        public readonly int $nbCles,
        public readonly ?\DateTimeImmutable $signedAt,
        /** Signée, mais des clés ont été remises depuis : le nombre attesté est dépassé */
        public readonly bool $aRenouveler = false,
    ) {}

    public function isSigned(): bool
    {
        return $this->signedAt !== null;
    }

    public function statutLabel(): string
    {
        return match (true) {
            !$this->isSigned() => 'Non',
            $this->aRenouveler => 'À renouveler',
            default => 'Oui',
        };
    }

    public function statutClass(): string
    {
        return match (true) {
            !$this->isSigned() => 'non',
            $this->aRenouveler => 'renouveler',
            default => 'oui',
        };
    }
}

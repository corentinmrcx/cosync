<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Dirigeant;

/**
 * État de détention d'une personne, dérivé de son historique de mouvements.
 * Jamais persisté : recalculé à chaque lecture par CleRegistreService.
 */
final class CleDetention
{
    public function __construct(
        public readonly Dirigeant $dirigeant,
        public readonly int $remises,
        public readonly int $restitutions,
        public readonly int $pertes,
        public readonly int $solde,
        /** Date de la remise qui a fait passer le solde de 0 à >0 — null si la personne ne détient plus rien */
        public readonly ?\DateTimeImmutable $detenteurDepuis,
        /** Date de la dernière remise, même si elle n'a fait qu'augmenter un solde déjà positif */
        public readonly ?\DateTimeImmutable $derniereRemiseLe,
        public readonly ?\DateTimeImmutable $dernierMouvementLe,
    ) {}

    public function estDetenteur(): bool
    {
        return $this->solde > 0;
    }

    public function aSigne(): bool
    {
        return $this->dirigeant->hasSignedAttestationCle();
    }

    /**
     * L'attestation signée ne correspond plus à la réalité : des clés ont été
     * remises après la signature, elle mentionne donc un nombre dépassé.
     */
    public function doitResigner(): bool
    {
        if (!$this->estDetenteur() || !$this->aSigne()) {
            return false;
        }

        $signedAt = $this->dirigeant->getAttestationCleSignedAt();

        return $signedAt !== null
            && $this->derniereRemiseLe !== null
            && $this->derniereRemiseLe > $signedAt;
    }

    /** L'attestation est-elle signée ET à jour ? */
    public function attestationAJour(): bool
    {
        return $this->aSigne() && !$this->doitResigner();
    }
}

<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * File d'attente en mémoire (scope requête) des dossiers dont le PDF doit être
 * uploadé sur Drive après l'envoi de la réponse HTTP, pour ne jamais bloquer
 * le licencié pendant l'appel à l'API Google.
 */
final class PendingUploadQueue
{
    /** @var int[] */
    private array $dossierIds = [];

    /** @var int[] dossiers dont l'attestation transport doit être uploadée */
    private array $attestationDossierIds = [];

    /** @var string[] uuids des dirigeants dont l'attestation transport doit être uploadée */
    private array $dirigeantAttestationUuids = [];

    /** @var string[] uuids des dirigeants dont le règlement signé doit être uploadé */
    private array $dirigeantReglementUuids = [];

    public function enqueue(int $dossierId): void
    {
        $this->dossierIds[] = $dossierId;
    }

    public function enqueueAttestation(int $dossierId): void
    {
        $this->attestationDossierIds[] = $dossierId;
    }

    public function enqueueDirigeantAttestation(string $dirigeantUuid): void
    {
        $this->dirigeantAttestationUuids[] = $dirigeantUuid;
    }

    public function enqueueDirigeantReglement(string $dirigeantUuid): void
    {
        $this->dirigeantReglementUuids[] = $dirigeantUuid;
    }

    /**
     * Retourne les dossiers en attente et vide la file.
     *
     * @return int[]
     */
    public function flush(): array
    {
        $ids = $this->dossierIds;
        $this->dossierIds = [];

        return $ids;
    }

    /**
     * @return int[]
     */
    public function flushAttestations(): array
    {
        $ids = $this->attestationDossierIds;
        $this->attestationDossierIds = [];

        return $ids;
    }

    /**
     * @return string[]
     */
    public function flushDirigeantAttestations(): array
    {
        $uuids = $this->dirigeantAttestationUuids;
        $this->dirigeantAttestationUuids = [];

        return $uuids;
    }

    /**
     * @return string[]
     */
    public function flushDirigeantReglements(): array
    {
        $uuids = $this->dirigeantReglementUuids;
        $this->dirigeantReglementUuids = [];

        return $uuids;
    }
}

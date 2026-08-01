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

    /** @var string[] uuids des dirigeants dont l'attestation de remise de clés doit être uploadée */
    private array $dirigeantAttestationCleUuids = [];

    /** @var int[] ids des saisons dont le récapitulatif des détenteurs doit être régénéré */
    private array $attestationCleRecapSeasonIds = [];

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

    public function enqueueDirigeantAttestationCle(string $dirigeantUuid): void
    {
        $this->dirigeantAttestationCleUuids[] = $dirigeantUuid;
    }

    /** Dédupliqué : plusieurs signatures dans une même requête ne régénèrent qu'un récapitulatif. */
    public function enqueueAttestationCleRecap(int $seasonId): void
    {
        if (!in_array($seasonId, $this->attestationCleRecapSeasonIds, true)) {
            $this->attestationCleRecapSeasonIds[] = $seasonId;
        }
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

    /**
     * @return string[]
     */
    public function flushDirigeantAttestationsCle(): array
    {
        $uuids = $this->dirigeantAttestationCleUuids;
        $this->dirigeantAttestationCleUuids = [];

        return $uuids;
    }

    /**
     * @return int[]
     */
    public function flushAttestationCleRecaps(): array
    {
        $ids = $this->attestationCleRecapSeasonIds;
        $this->attestationCleRecapSeasonIds = [];

        return $ids;
    }
}

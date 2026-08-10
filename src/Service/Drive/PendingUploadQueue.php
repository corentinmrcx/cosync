<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * File d'attente en mémoire (scope requête) des dossiers dont le PDF doit être
 * uploadé sur Drive après l'envoi de la réponse HTTP, pour ne jamais bloquer
 * le licencié pendant l'appel à l'API Google.
 */
final class PendingUploadQueue
{
    /**
     * @var int[] ids des signatures de documents dont le PDF doit être uploadé.
     * Une seule file pour tous les documents signables : leur nombre n'étant plus
     * borné par le code, en ajouter un ne doit pas ajouter de file.
     */
    private array $documentSignatureIds = [];

    /** @var int[] dossiers dont l'attestation transport doit être uploadée */
    private array $attestationDossierIds = [];

    /** @var string[] uuids des dirigeants dont l'attestation transport doit être uploadée */
    private array $dirigeantAttestationUuids = [];

    /** @var int[] ids des attestations de remise de clés dont le PDF doit être uploadé */
    private array $attestationCleIds = [];

    /** @var int[] ids des saisons dont le récapitulatif des détenteurs doit être régénéré */
    private array $attestationCleRecapSeasonIds = [];

    public function enqueueDocumentSignature(int $signatureId): void
    {
        $this->documentSignatureIds[] = $signatureId;
    }

    public function enqueueAttestation(int $dossierId): void
    {
        $this->attestationDossierIds[] = $dossierId;
    }

    public function enqueueDirigeantAttestation(string $dirigeantUuid): void
    {
        $this->dirigeantAttestationUuids[] = $dirigeantUuid;
    }

    public function enqueueAttestationCle(int $attestationId): void
    {
        $this->attestationCleIds[] = $attestationId;
    }

    /** Dédupliqué : plusieurs signatures dans une même requête ne régénèrent qu'un récapitulatif. */
    public function enqueueAttestationCleRecap(int $seasonId): void
    {
        if (!in_array($seasonId, $this->attestationCleRecapSeasonIds, true)) {
            $this->attestationCleRecapSeasonIds[] = $seasonId;
        }
    }

    /**
     * Retourne les signatures en attente et vide la file.
     *
     * @return int[]
     */
    public function flushDocumentSignatures(): array
    {
        $ids = $this->documentSignatureIds;
        $this->documentSignatureIds = [];

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
     * @return int[]
     */
    public function flushAttestationsCle(): array
    {
        $ids = $this->attestationCleIds;
        $this->attestationCleIds = [];

        return $ids;
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

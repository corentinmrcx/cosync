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

    public function enqueue(int $dossierId): void
    {
        $this->dossierIds[] = $dossierId;
    }

    public function enqueueAttestation(int $dossierId): void
    {
        $this->attestationDossierIds[] = $dossierId;
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
}

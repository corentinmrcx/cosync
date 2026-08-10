<?php declare(strict_types=1);

namespace App\EventListener;

use App\Repository\AttestationCleRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignatureRepository;
use App\Repository\DossierClubRepository;
use App\Repository\SeasonRepository;
use App\Service\Drive\AttestationCleDriveSync;
use App\Service\Drive\AttestationCleRecapDriveSync;
use App\Service\Drive\AttestationDriveSync;
use App\Service\Drive\DirigeantAttestationDriveSync;
use App\Service\Drive\DocumentSignatureDriveSync;
use App\Service\Drive\PendingUploadQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Réalise les uploads Drive en attente APRÈS l'envoi de la réponse au client
 * (kernel.terminate). Le licencié reçoit immédiatement sa page de confirmation,
 * l'upload se fait ensuite sans le bloquer ni dépendre de la disponibilité de
 * l'API Google.
 *
 * Chaque itération est isolée : l'échec d'un document ne doit jamais empêcher les
 * suivants de partir.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class DriveUploadTerminateListener
{
    public function __construct(
        private readonly PendingUploadQueue $queue,
        private readonly DossierClubRepository $dossierRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DocumentSignatureRepository $signatureRepository,
        private readonly DocumentSignatureDriveSync $documentDriveSync,
        private readonly AttestationDriveSync $attestationDriveSync,
        private readonly DirigeantAttestationDriveSync $dirigeantAttestationDriveSync,
        private readonly SeasonRepository $seasonRepository,
        private readonly AttestationCleRepository $attestationCleRepository,
        private readonly AttestationCleDriveSync $attestationCleDriveSync,
        private readonly AttestationCleRecapDriveSync $attestationCleRecapDriveSync,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($this->queue->flushDocumentSignatures() as $signatureId) {
            try {
                $signature = $this->signatureRepository->find($signatureId);

                if ($signature !== null) {
                    $this->documentDriveSync->sync($signature);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec archivage du document signé {id} : {message}', [
                    'id' => $signatureId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->queue->flushAttestations() as $dossierId) {
            try {
                $dossier = $this->dossierRepository->find($dossierId);

                if ($dossier !== null) {
                    $this->attestationDriveSync->sync($dossier);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec sync attestation transport du dossier {id} : {message}', [
                    'id' => $dossierId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->queue->flushDirigeantAttestations() as $dirigeantUuid) {
            try {
                $dirigeant = $this->dirigeantRepository->findByUuid(Uuid::fromString($dirigeantUuid));

                if ($dirigeant !== null) {
                    $this->dirigeantAttestationDriveSync->sync($dirigeant);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec sync attestation transport du dirigeant {uuid} : {message}', [
                    'uuid' => $dirigeantUuid,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Feuille individuelle puis récapitulatif : chaque itération est isolée pour
        // que l'échec de l'une n'empêche pas l'autre de partir.
        foreach ($this->queue->flushAttestationsCle() as $attestationId) {
            try {
                $attestation = $this->attestationCleRepository->find($attestationId);

                if ($attestation !== null) {
                    $this->attestationCleDriveSync->sync($attestation);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec sync attestation de remise de clés {id} : {message}', [
                    'id' => $attestationId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->queue->flushAttestationCleRecaps() as $seasonId) {
            try {
                $season = $this->seasonRepository->find($seasonId);

                if ($season !== null) {
                    $this->attestationCleRecapDriveSync->sync($season);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec régénération du récapitulatif des détenteurs (saison {id}) : {message}', [
                    'id' => $seasonId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}

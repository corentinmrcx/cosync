<?php declare(strict_types=1);

namespace App\EventListener;

use App\Repository\DirigeantRepository;
use App\Repository\DossierClubRepository;
use App\Repository\SeasonRepository;
use App\Service\Drive\AttestationDriveSync;
use App\Service\Drive\AttestationCleRecapDriveSync;
use App\Service\Drive\DirigeantAttestationDriveSync;
use App\Service\Drive\DirigeantAttestationCleDriveSync;
use App\Service\Drive\DirigeantReglementDriveSync;
use App\Service\Drive\DossierDriveSync;
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
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class DriveUploadTerminateListener
{
    public function __construct(
        private readonly PendingUploadQueue $queue,
        private readonly DossierClubRepository $dossierRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DossierDriveSync $driveSync,
        private readonly AttestationDriveSync $attestationDriveSync,
        private readonly DirigeantAttestationDriveSync $dirigeantAttestationDriveSync,
        private readonly DirigeantReglementDriveSync $dirigeantReglementDriveSync,
        private readonly SeasonRepository $seasonRepository,
        private readonly DirigeantAttestationCleDriveSync $dirigeantAttestationCleDriveSync,
        private readonly AttestationCleRecapDriveSync $attestationCleRecapDriveSync,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($this->queue->flush() as $dossierId) {
            $dossier = $this->dossierRepository->find($dossierId);

            if ($dossier !== null) {
                $this->driveSync->sync($dossier);
            }
        }

        foreach ($this->queue->flushAttestations() as $dossierId) {
            $dossier = $this->dossierRepository->find($dossierId);

            if ($dossier !== null) {
                $this->attestationDriveSync->sync($dossier);
            }
        }

        foreach ($this->queue->flushDirigeantAttestations() as $dirigeantUuid) {
            $dirigeant = $this->dirigeantRepository->findByUuid(Uuid::fromString($dirigeantUuid));

            if ($dirigeant !== null) {
                $this->dirigeantAttestationDriveSync->sync($dirigeant);
            }
        }

        foreach ($this->queue->flushDirigeantReglements() as $dirigeantUuid) {
            try {
                $dirigeant = $this->dirigeantRepository->findByUuid(Uuid::fromString($dirigeantUuid));

                if ($dirigeant !== null) {
                    $this->dirigeantReglementDriveSync->sync($dirigeant);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec sync du règlement dirigeants de {uuid} : {message}', [
                    'uuid'    => $dirigeantUuid,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Feuille individuelle puis récapitulatif : chaque itération est isolée pour
        // que l'échec de l'une n'empêche pas l'autre de partir.
        foreach ($this->queue->flushDirigeantAttestationsCle() as $dirigeantUuid) {
            try {
                $dirigeant = $this->dirigeantRepository->findByUuid(Uuid::fromString($dirigeantUuid));

                if ($dirigeant !== null) {
                    $this->dirigeantAttestationCleDriveSync->sync($dirigeant);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Échec sync attestation de remise du dirigeant {uuid} : {message}', [
                    'uuid'    => $dirigeantUuid,
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
                    'id'      => $seasonId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}

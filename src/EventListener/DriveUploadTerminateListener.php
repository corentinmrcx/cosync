<?php declare(strict_types=1);

namespace App\EventListener;

use App\Repository\DirigeantRepository;
use App\Repository\DossierClubRepository;
use App\Service\Drive\AttestationDriveSync;
use App\Service\Drive\DirigeantAttestationDriveSync;
use App\Service\Drive\DossierDriveSync;
use App\Service\Drive\PendingUploadQueue;
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
    }
}

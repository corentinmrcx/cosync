<?php declare(strict_types=1);

namespace App\EventListener;

use App\Repository\DossierClubRepository;
use App\Service\Drive\DossierDriveSync;
use App\Service\Drive\PendingUploadQueue;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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
        private readonly DossierDriveSync $driveSync,
    ) {}

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($this->queue->flush() as $dossierId) {
            $dossier = $this->dossierRepository->find($dossierId);

            if ($dossier !== null) {
                $this->driveSync->sync($dossier);
            }
        }
    }
}

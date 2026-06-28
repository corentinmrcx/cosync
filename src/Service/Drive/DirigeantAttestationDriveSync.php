<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise l'attestation transport PDF d'un dirigeant vers Drive
 * (pattern identique à AttestationDriveSync pour les licenciés).
 */
final class DirigeantAttestationDriveSync
{
    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly EntityManagerInterface $em,
    ) {}

    public function sync(Dirigeant $dirigeant): bool
    {
        $localPath = $dirigeant->getAttestationTransportDriveId();

        // Déjà sur Drive (pas un chemin local) → rien à faire.
        if ($localPath === null || !str_starts_with($localPath, '/')) {
            return $localPath !== null;
        }

        if (!file_exists($localPath)) {
            return false;
        }

        $filename = basename($localPath);

        $driveId = $this->driveUploader->uploadToSubFolder(
            $localPath,
            $dirigeant->getSeason()->getLabel(),
            'Attestations Transport',
            $filename,
            (string) $dirigeant->getUuid(),
        );

        if ($driveId === null) {
            return false;
        }

        $dirigeant->setAttestationTransportDriveId($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }
}

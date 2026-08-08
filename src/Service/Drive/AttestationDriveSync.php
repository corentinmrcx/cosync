<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\DossierClub;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise l'attestation transport PDF d'un dossier vers Drive (pattern
 * identique à DocumentSignatureDriveSync pour les documents signés).
 */
final class AttestationDriveSync
{
    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly EntityManagerInterface $em,
    ) {}

    public function sync(DossierClub $dossier): bool
    {
        $localPath = $dossier->getAttestationTransportDriveId();

        // Déjà sur Drive (pas un chemin local) → rien à faire.
        if ($localPath === null || !str_starts_with($localPath, '/')) {
            return $localPath !== null;
        }

        if (!file_exists($localPath)) {
            return false;
        }

        $licencie = $dossier->getLicencie();
        $filename = basename($localPath);

        $driveId = $this->driveUploader->uploadToSubFolder(
            $localPath,
            $licencie->getSeason()->getLabel(),
            'Attestations Transport',
            $filename,
            (string) $licencie->getUuid(),
        );

        if ($driveId === null) {
            return false;
        }

        $dossier->setAttestationTransportDriveId($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }

}

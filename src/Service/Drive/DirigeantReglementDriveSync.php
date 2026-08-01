<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise le règlement intérieur des dirigeants signé vers Drive
 * (pattern identique à DirigeantAttestationDriveSync).
 *
 * Archivé dans un sous-dossier « Dirigeants » : ce n'est pas le même document
 * que le règlement signé par les licenciés, les mélanger serait trompeur.
 */
final class DirigeantReglementDriveSync
{
    private const DRIVE_PATH = ['Règlements intérieurs signés', 'Dirigeants'];

    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly DriveFilenameSanitizer $sanitizer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function sync(Dirigeant $dirigeant): bool
    {
        $localPath = $dirigeant->getReglementSignePath();

        // Déjà sur Drive (pas un chemin local) → rien à faire.
        if ($localPath === null || !str_starts_with($localPath, '/')) {
            return $localPath !== null;
        }

        if (!file_exists($localPath)) {
            return false;
        }

        $driveId = $this->driveUploader->uploadToPath(
            $localPath,
            $dirigeant->getSeason()->getLabel(),
            self::DRIVE_PATH,
            $this->buildFilename($dirigeant),
            (string) $dirigeant->getUuid(),
        );

        if ($driveId === null) {
            return false;
        }

        $dirigeant->setReglementSignePath($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }

    private function buildFilename(Dirigeant $dirigeant): string
    {
        return sprintf(
            'RI_%s_%s_Dirigeant.pdf',
            $this->sanitizer->sanitize($dirigeant->getNom()),
            $this->sanitizer->sanitize($dirigeant->getPrenom()),
        );
    }
}

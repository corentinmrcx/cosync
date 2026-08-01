<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive l'attestation de remise de clés signée d'un dirigeant sur Drive,
 * dans {saison}/Club house/Clés/Attestations de remise/.
 *
 * En cas d'échec, le fichier local est conservé : il porte la seule copie de la
 * signature, le rattrapage se fait par DriveRetryUploadCommand.
 */
final class DirigeantAttestationCleDriveSync
{
    /** @var string[] */
    private const DRIVE_PATH = ['Club house', 'Clés', 'Attestations de remise'];

    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly DriveFilenameSanitizer $sanitizer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function sync(Dirigeant $dirigeant): bool
    {
        $localPath = $dirigeant->getAttestationCleSignePath();

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

        $dirigeant->setAttestationCleSignePath($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }

    private function buildFilename(Dirigeant $dirigeant): string
    {
        return sprintf(
            'Attestation_cle_%s_%s.pdf',
            $this->sanitizer->sanitize($dirigeant->getNom()),
            $this->sanitizer->sanitize($dirigeant->getPrenom()),
        );
    }
}

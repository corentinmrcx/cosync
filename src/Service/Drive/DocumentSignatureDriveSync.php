<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\DocumentSignature;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive sur Drive le PDF d'un document signé, quel que soit le document et quel que
 * soit le signataire : le chemin de destination et le nom de fichier sont portés par le
 * document lui-même. Remplace les services d'archivage qui existaient par document.
 *
 * En cas d'échec, le PDF reste en local et sera retenté par app:drive-retry-upload.
 */
final class DocumentSignatureDriveSync
{
    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly DriveFilenameSanitizer $sanitizer,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Retourne true si le PDF est désormais sur Drive (ou l'était déjà),
     * false si l'upload a échoué ou si le fichier local est introuvable.
     */
    public function sync(DocumentSignature $signature): bool
    {
        $localPath = $signature->getDrivePath();

        // Déjà sur Drive (l'ID n'est pas un chemin de fichier local) → rien à faire.
        if ($localPath === null || !str_starts_with($localPath, '/')) {
            return $localPath !== null;
        }

        if (!file_exists($localPath)) {
            return false;
        }

        $document = $signature->getDocument();

        $driveId = $this->driveUploader->uploadToPath(
            $localPath,
            $document->getSeason()->getLabel(),
            $document->getDriveSegments(),
            $this->buildFilename($signature),
            (string) $signature->getId(),
        );

        if ($driveId === null) {
            return false;
        }

        $signature->setDrivePath($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }

    private function buildFilename(DocumentSignature $signature): string
    {
        return sprintf(
            '%s_%s_%s.pdf',
            $signature->getDocument()->getFilePrefix(),
            $this->sanitizer->sanitize($signature->getNom()),
            $this->sanitizer->sanitize($signature->getPrenom()),
        );
    }
}

<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\DocumentSignature;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive le PDF d'un document signé, quel que soit le document et quel que soit le
 * signataire : le chemin de destination et le préfixe de fichier sont portés par le
 * document lui-même.
 *
 * @extends LocalFileDriveSync<DocumentSignature>
 */
final class DocumentSignatureDriveSync extends LocalFileDriveSync
{
    public function __construct(
        DriveUploaderService $driveUploader,
        EntityManagerInterface $em,
        private readonly DriveFilenameSanitizer $sanitizer,
    ) {
        parent::__construct($driveUploader, $em);
    }

    protected function cheminActuel(object $sujet): ?string
    {
        return $sujet->getDrivePath();
    }

    protected function enregistrerDriveId(object $sujet, string $driveId): void
    {
        $sujet->setDrivePath($driveId);
    }

    protected function destination(object $sujet): DriveDestination
    {
        $document = $sujet->getDocument();

        return new DriveDestination(
            $document->getSeason()->getLabel(),
            $document->getDriveSegments(),
            sprintf(
                '%s_%s_%s.pdf',
                $document->getFilePrefix(),
                $this->sanitizer->sanitize($sujet->getNom()),
                $this->sanitizer->sanitize($sujet->getPrenom()),
            ),
            (string) $sujet->getId(),
        );
    }
}

<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise le règlement intérieur signé d'un dirigeant vers Drive
 * (pattern identique à DirigeantAttestationDriveSync).
 */
final class DirigeantReglementDriveSync
{
    public function __construct(
        private readonly DriveUploaderService $driveUploader,
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

        $driveId = $this->driveUploader->uploadToSubFolder(
            $localPath,
            $dirigeant->getSeason()->getLabel(),
            'Règlements intérieurs signés',
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
        $sanitize = static function (string $value): string {
            $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
            $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);

            return trim($value, '_');
        };

        return sprintf('RI_%s_%s_Dirigeant.pdf', $sanitize($dirigeant->getNom()), $sanitize($dirigeant->getPrenom()));
    }
}

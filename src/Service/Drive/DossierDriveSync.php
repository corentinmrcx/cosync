<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\DossierClub;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise le PDF signé d'un dossier vers Drive : upload, mise à jour du
 * chemin (ID Drive) et suppression du fichier local en cas de succès.
 * En cas d'échec, le PDF reste en local et sera retenté plus tard.
 */
final class DossierDriveSync
{
    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Retourne true si le PDF est désormais sur Drive (ou déjà uploadé),
     * false si l'upload a échoué ou si le fichier local est introuvable.
     */
    public function sync(DossierClub $dossier): bool
    {
        $localPath = $dossier->getSignaturePath();

        // Déjà sur Drive (l'ID n'est pas un chemin de fichier local) → rien à faire.
        if ($localPath === null || !str_starts_with($localPath, '/')) {
            return $localPath !== null;
        }

        if (!file_exists($localPath)) {
            return false;
        }

        $driveId = $this->driveUploader->upload($localPath, $dossier->getLicencie());

        if ($driveId === null) {
            return false;
        }

        $dossier->setSignaturePath($driveId);
        $this->em->flush();
        @unlink($localPath);

        return true;
    }
}

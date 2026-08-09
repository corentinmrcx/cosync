<?php declare(strict_types=1);

namespace App\Service\Drive;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Archivage d'un PDF signé sur le Drive du club.
 *
 * Le fichier local n'est supprimé qu'après un upload réussi : tant que Drive est
 * injoignable, il porte la seule copie de la signature. Les échecs sont rattrapés par
 * app:drive-retry-upload.
 *
 * @template T of object
 */
abstract class LocalFileDriveSync
{
    public function __construct(
        protected readonly DriveUploaderService $driveUploader,
        protected readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param T $sujet
     *
     * @return bool true si le PDF est sur Drive (ou l'était déjà), false si l'upload a
     *              échoué ou si le fichier local est introuvable
     */
    public function sync(object $sujet): bool
    {
        $cheminLocal = $this->cheminActuel($sujet);

        if (!DrivePath::estLocal($cheminLocal)) {
            return $cheminLocal !== null;
        }

        if (!file_exists($cheminLocal)) {
            return false;
        }

        $destination = $this->destination($sujet);

        $driveId = $this->driveUploader->uploadToPath(
            $cheminLocal,
            $destination->saison,
            $destination->segments,
            $destination->nomFichier,
            $destination->referenceLog,
        );

        if ($driveId === null) {
            return false;
        }

        $this->enregistrerDriveId($sujet, $driveId);
        $this->em->flush();
        @unlink($cheminLocal);

        return true;
    }

    /** @param T $sujet */
    abstract protected function cheminActuel(object $sujet): ?string;

    /** @param T $sujet */
    abstract protected function enregistrerDriveId(object $sujet, string $driveId): void;

    /** @param T $sujet */
    abstract protected function destination(object $sujet): DriveDestination;
}

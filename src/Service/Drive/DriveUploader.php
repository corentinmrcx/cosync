<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * Dépôt d'un fichier sur le Drive du club.
 *
 * L'interface existe pour que l'archivage soit vérifiable sans réseau : c'est la
 * seule étape où une signature peut être perdue.
 */
interface DriveUploader
{
    /**
     * Dépose un fichier sous le dossier de la saison, en créant les dossiers manquants.
     *
     * @param string[] $segments
     *
     * @return string|null identifiant Drive du fichier créé, null si l'upload a échoué
     */
    public function uploadToPath(string $localPdfPath, string $seasonLabel, array $segments, string $filename, string $logRef = ''): ?string;
}

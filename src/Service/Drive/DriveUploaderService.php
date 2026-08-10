<?php declare(strict_types=1);

namespace App\Service\Drive;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DriveUploaderService implements DriveUploader
{
    public function __construct(
        #[Autowire('%env(GOOGLE_DRIVE_CREDENTIALS_JSON)%')] private readonly string $credentialsPath,
        #[Autowire('%env(GOOGLE_DRIVE_FOLDER_ID)%')] private readonly string $rootFolderId,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upload générique vers n'importe quel sous-dossier de la saison.
     * Réutilisable pour les attestations transport, documents dirigeants, etc.
     * Retourne l'ID Drive du fichier créé, ou null en cas d'échec.
     */
    public function uploadToSubFolder(string $localPdfPath, string $seasonLabel, string $subFolder, string $filename, string $logRef = ''): ?string
    {
        return $this->uploadToPath($localPdfPath, $seasonLabel, [$subFolder], $filename, $logRef);
    }

    /**
     * Upload vers un chemin imbriqué sous le dossier de saison, les dossiers manquants
     * étant créés à la volée. Ex. : ['Club house', 'Clés', 'Attestations de remise'].
     * Retourne l'ID Drive du fichier créé, ou null en cas d'échec.
     *
     * @param string[] $segments
     */
    public function uploadToPath(string $localPdfPath, string $seasonLabel, array $segments, string $filename, string $logRef = ''): ?string
    {
        if ($this->credentialsPath === '' || $this->rootFolderId === '') {
            $this->logger->warning('Google Drive non configuré (variables d\'env manquantes). PDF conservé en local.');

            return null;
        }

        try {
            $service = $this->buildDriveService();
            $folder = $this->resolvePath($service, $seasonLabel, $segments);

            return $this->uploadFile($service, $localPdfPath, $folder, $filename);
        } catch (\Throwable $e) {
            $this->logger->error('Échec upload Drive ({ref}) : {message}', [
                'ref' => $logRef ?: $filename,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Écrit un fichier à un emplacement fixe : remplace son contenu s'il existe déjà,
     * le crée sinon. Utilisé pour les documents régénérés (récapitulatif des détenteurs de clés),
     * ce qui préserve l'ID Drive et les partages existants.
     *
     * @param string[] $segments
     */
    public function replaceAtPath(string $localPdfPath, string $seasonLabel, array $segments, string $filename, string $logRef = ''): ?string
    {
        if ($this->credentialsPath === '' || $this->rootFolderId === '') {
            $this->logger->warning('Google Drive non configuré (variables d\'env manquantes). PDF conservé en local.');

            return null;
        }

        try {
            $service = $this->buildDriveService();
            $folderId = $this->resolvePath($service, $seasonLabel, $segments);
            $existing = $this->findFileIdByName($service, $filename, $folderId);

            if ($existing === null) {
                return $this->uploadFile($service, $localPdfPath, $folderId, $filename);
            }

            $content = file_get_contents($localPdfPath);
            if ($content === false) {
                throw new \RuntimeException(sprintf('Impossible de lire le fichier local : %s', $localPdfPath));
            }

            // Pas de 'parents' dans un update : l'API Drive exige addParents/removeParents.
            $updated = $service->files->update($existing, new DriveFile(['name' => $filename]), [
                'data' => $content,
                'mimeType' => 'application/pdf',
                'uploadType' => 'multipart',
                'fields' => 'id',
                'supportsAllDrives' => true,
            ]);

            return $updated->getId();
        } catch (\Throwable $e) {
            $this->logger->error('Échec remplacement Drive ({ref}) : {message}', [
                'ref' => $logRef ?: $filename,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Upload vers un chemin imbriqué directement sous le dossier racine, hors arborescence
     * de saison. Utilisé pour les sauvegardes de base (Sauvegardes/{YYYY-MM}/).
     *
     * @param string[] $segments
     */
    public function uploadToRoot(string $localPath, array $segments, string $filename, string $mimeType = 'application/pdf', string $logRef = ''): ?string
    {
        if ($this->credentialsPath === '' || $this->rootFolderId === '') {
            $this->logger->warning('Google Drive non configuré (variables d\'env manquantes). Fichier conservé en local.');

            return null;
        }

        try {
            $service = $this->buildDriveService();
            $parentId = $this->rootFolderId;

            foreach ($segments as $segment) {
                $parentId = $this->findOrCreateFolder($service, $segment, $parentId);
            }

            return $this->uploadFile($service, $localPath, $parentId, $filename, $mimeType);
        } catch (\Throwable $e) {
            $this->logger->error('Échec upload Drive ({ref}) : {message}', [
                'ref' => $logRef ?: $filename,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildDriveService(): Drive
    {
        if (!file_exists($this->credentialsPath)) {
            throw new \RuntimeException(sprintf('Fichier credentials introuvable : %s', $this->credentialsPath));
        }

        $client = new Client();
        $client->setAuthConfig($this->credentialsPath);
        $client->addScope(Drive::DRIVE);

        return new Drive($client);
    }

    /** @param string[] $segments */
    private function resolvePath(Drive $service, string $seasonLabel, array $segments): string
    {
        $parentId = $this->findOrCreateFolder($service, $seasonLabel, $this->rootFolderId);

        foreach ($segments as $segment) {
            $parentId = $this->findOrCreateFolder($service, $segment, $parentId);
        }

        return $parentId;
    }

    private function findFileIdByName(Drive $service, string $name, string $parentId): ?string
    {
        $escaped = str_replace("'", "\\'", $name);
        $q = "name = '$escaped' and '$parentId' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed = false";

        $results = $service->files->listFiles([
            'q' => $q,
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        return count($results->getFiles()) > 0 ? $results->getFiles()[0]->getId() : null;
    }

    private function findOrCreateFolder(Drive $service, string $name, string $parentId): string
    {
        $escaped = str_replace("'", "\\'", $name);
        $q = "name = '$escaped' and '$parentId' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

        $results = $service->files->listFiles([
            'q' => $q,
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $folder = new DriveFile(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]]);
        $created = $service->files->create($folder, ['fields' => 'id', 'supportsAllDrives' => true]);

        return $created->getId();
    }

    private function uploadFile(Drive $service, string $localPath, string $folderId, string $filename, string $mimeType = 'application/pdf'): string
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier local : %s', $localPath));
        }

        $meta = new DriveFile(['name' => $filename, 'parents' => [$folderId]]);
        $created = $service->files->create($meta, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id',
            'supportsAllDrives' => true,
        ]);

        return $created->getId();
    }
}

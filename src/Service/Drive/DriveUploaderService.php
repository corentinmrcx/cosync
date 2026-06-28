<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Licencie;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DriveUploaderService
{
    public function __construct(
        #[Autowire('%env(GOOGLE_DRIVE_CREDENTIALS_JSON)%')] private readonly string $credentialsPath,
        #[Autowire('%env(GOOGLE_DRIVE_FOLDER_ID)%')] private readonly string $rootFolderId,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upload le PDF signé sur Drive dans Saison/{label}/Règlements intérieurs signés/.
     * Retourne l'ID Drive du fichier créé, ou null en cas d'échec.
     */
    public function upload(string $localPdfPath, Licencie $licencie): ?string
    {
        return $this->uploadToSubFolder(
            $localPdfPath,
            $licencie->getSeason()->getLabel(),
            'Règlements intérieurs signés',
            $this->buildFilename($licencie),
            (string) $licencie->getUuid(),
        );
    }

    /**
     * Upload générique vers n'importe quel sous-dossier de la saison.
     * Réutilisable pour les attestations transport, documents dirigeants, etc.
     * Retourne l'ID Drive du fichier créé, ou null en cas d'échec.
     */
    public function uploadToSubFolder(string $localPdfPath, string $seasonLabel, string $subFolder, string $filename, string $logRef = ''): ?string
    {
        if ($this->credentialsPath === '' || $this->rootFolderId === '') {
            $this->logger->warning('Google Drive non configuré (variables d\'env manquantes). PDF conservé en local.');
            return null;
        }

        try {
            $service = $this->buildDriveService();
            $folder  = $this->resolveSubFolder($service, $seasonLabel, $subFolder);

            return $this->uploadFile($service, $localPdfPath, $folder, $filename);
        } catch (\Throwable $e) {
            $this->logger->error('Échec upload Drive ({ref}) : {message}', [
                'ref'     => $logRef ?: $filename,
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

    private function resolveSubFolder(Drive $service, string $seasonLabel, string $subFolder): string
    {
        $seasonFolderId = $this->findOrCreateFolder($service, $seasonLabel, $this->rootFolderId);

        return $this->findOrCreateFolder($service, $subFolder, $seasonFolderId);
    }

    private function findOrCreateFolder(Drive $service, string $name, string $parentId): string
    {
        $escaped = str_replace("'", "\\'", $name);
        $q       = "name = '$escaped' and '$parentId' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

        $results = $service->files->listFiles([
            'q'                         => $q,
            'fields'                    => 'files(id)',
            'pageSize'                  => 1,
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $folder  = new DriveFile(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]]);
        $created = $service->files->create($folder, ['fields' => 'id', 'supportsAllDrives' => true]);

        return $created->getId();
    }

    private function uploadFile(Drive $service, string $localPath, string $folderId, string $filename): string
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier local : %s', $localPath));
        }

        $meta    = new DriveFile(['name' => $filename, 'parents' => [$folderId]]);
        $created = $service->files->create($meta, [
            'data'              => $content,
            'mimeType'          => 'application/pdf',
            'uploadType'        => 'multipart',
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ]);

        return $created->getId();
    }

    private function buildFilename(Licencie $licencie): string
    {
        $nom      = $this->sanitize($licencie->getNom());
        $prenom   = $this->sanitize($licencie->getPrenom());
        $categorie = $this->sanitize($licencie->getCategory()->getCode());

        return sprintf('RI_%s_%s_%s.pdf', $nom, $prenom, $categorie);
    }

    private function sanitize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}

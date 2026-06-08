<?php declare(strict_types=1);

namespace App\Command;

use Google\Client;
use Google\Service\Drive;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:test-drive', description: 'Teste la connexion Google Drive')]
final class TestDriveCommand extends Command
{
    public function __construct(
        #[Autowire('%env(GOOGLE_DRIVE_CREDENTIALS_JSON)%')] private readonly string $credentialsPath,
        #[Autowire('%env(GOOGLE_DRIVE_FOLDER_ID)%')] private readonly string $rootFolderId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('1. Vérification des variables d\'env');
        $io->text('GOOGLE_DRIVE_CREDENTIALS_JSON : ' . ($this->credentialsPath ?: '(vide)'));
        $io->text('GOOGLE_DRIVE_FOLDER_ID        : ' . ($this->rootFolderId ?: '(vide)'));

        $io->section('2. Vérification du fichier credentials');
        if (!file_exists($this->credentialsPath)) {
            $io->error(sprintf('Fichier introuvable : %s', $this->credentialsPath));
            return Command::FAILURE;
        }
        $io->success('Fichier credentials trouvé.');

        $credentials = json_decode((string) file_get_contents($this->credentialsPath), true);
        $io->text('Service Account : ' . ($credentials['client_email'] ?? '(inconnu)'));

        $io->section('3. Authentification Google');
        try {
            $client = new Client();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope(Drive::DRIVE);
            $service = new Drive($client);
            $io->success('Authentification OK.');
        } catch (\Throwable $e) {
            $io->error('Échec authentification : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('4. Dossiers partagés avec le Service Account');
        try {
            $shared = $service->files->listFiles([
                'q'      => "sharedWithMe = true and mimeType = 'application/vnd.google-apps.folder'",
                'fields' => 'files(id, name)',
                'pageSize' => 20,
            ]);
            $sharedFolders = $shared->getFiles();
            if (empty($sharedFolders)) {
                $io->warning('Aucun dossier partagé avec ce Service Account. Le partage n\'est pas effectif.');
            } else {
                $io->text('Dossiers accessibles :');
                foreach ($sharedFolders as $f) {
                    $marker = $f->getId() === $this->rootFolderId ? ' ← CIBLE' : '';
                    $io->text(sprintf('  - "%s" (ID: %s)%s', $f->getName(), $f->getId(), $marker));
                }
            }
        } catch (\Throwable $e) {
            $io->error('Erreur listFiles : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('5. Accès direct au dossier racine');
        try {
            $folder = $service->files->get($this->rootFolderId, ['fields' => 'id, name', 'supportsAllDrives' => true]);
            $io->success(sprintf('Dossier trouvé : "%s"', $folder->getName()));
        } catch (\Throwable $e) {
            $io->error('Accès direct échoué : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('6. Test création sous-dossier');
        try {
            $q       = "name = '_test_cosync' and '{$this->rootFolderId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
            $results = $service->files->listFiles([
                'q'                         => $q,
                'fields'                    => 'files(id)',
                'pageSize'                  => 1,
                'supportsAllDrives'         => true,
                'includeItemsFromAllDrives' => true,
            ]);

            if (count($results->getFiles()) === 0) {
                $meta    = new Drive\DriveFile(['name' => '_test_cosync', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$this->rootFolderId]]);
                $created = $service->files->create($meta, ['fields' => 'id', 'supportsAllDrives' => true]);
                $io->success('Sous-dossier de test créé (ID: ' . $created->getId() . ')');
                try {
                    $service->files->delete($created->getId(), ['supportsAllDrives' => true]);
                    $io->text('Sous-dossier de test supprimé.');
                } catch (\Throwable) {
                    $io->note('Suppression du dossier de test ignorée (non critique). Supprimez "_test_cosync" manuellement dans Drive.');
                }
            } else {
                $io->success('Accès en écriture confirmé.');
            }
        } catch (\Throwable $e) {
            $io->error('Échec : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Tout est opérationnel. L\'upload Drive devrait fonctionner.');
        return Command::SUCCESS;
    }
}

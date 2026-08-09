<?php declare(strict_types=1);

namespace App\Command;

use App\Service\Ops\DatabaseBackupService;
use App\Service\Drive\DriveUploaderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:backup',
    description: 'Sauvegarde la base PostgreSQL (dump local + copie sur le Drive du club)',
)]
final class DatabaseBackupCommand extends Command
{
    private const RETENTION_JOURS = 30;

    public function __construct(
        private readonly DatabaseBackupService $backupService,
        private readonly DriveUploaderService $driveUploader,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sans-drive', null, InputOption::VALUE_NONE, 'Ne fait que le dump local, sans copie off-site')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'Nombre de jours de conservation locale', (string) self::RETENTION_JOURS);
    }

    /**
     * Le dump local est la sauvegarde qui compte : un échec de l'upload Drive n'annule
     * pas la commande, il est signalé et journalisé. À l'inverse, un dump raté ou
     * suspect fait échouer la commande — c'est le seul moyen que la panne se voie.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $path = $this->backupService->dump();
        } catch (\RuntimeException $e) {
            $this->logger->error('Sauvegarde de la base impossible : {message}', ['message' => $e->getMessage()]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $nomFichier = basename($path);
        $io->success(sprintf('Dump créé : %s (%s)', $nomFichier, $this->formaterTaille((int) filesize($path))));

        $codeSortie = Command::SUCCESS;

        if ($input->getOption('sans-drive')) {
            $io->note('Copie Drive ignorée (--sans-drive).');
        } else {
            $segments = $this->backupService->driveSegments();
            $driveId = $this->driveUploader->uploadToRoot(
                $path,
                $segments,
                $nomFichier,
                DatabaseBackupService::MIME_TYPE,
                $nomFichier,
            );

            if ($driveId === null) {
                // Le dump local existe : on alerte sans échouer, le cron réessaiera demain.
                $this->logger->error('Sauvegarde {fichier} : copie Drive impossible, dump conservé en local uniquement.', [
                    'fichier' => $nomFichier,
                ]);
                $io->warning('Copie Drive impossible — la sauvegarde n\'existe que sur le VPS. Voir les logs.');
                $codeSortie = Command::SUCCESS;
            } else {
                $io->writeln(sprintf('Copie Drive : %s (id %s)', implode('/', $segments), $driveId));
            }
        }

        $retention = max(1, (int) $input->getOption('retention'));
        $supprimes = $this->backupService->purgerAnciens($retention);

        $io->writeln(sprintf(
            '%d sauvegarde(s) de plus de %d jours supprimée(s) — %d conservée(s) localement.',
            count($supprimes),
            $retention,
            count($this->backupService->listerDumps()),
        ));

        return $codeSortie;
    }

    private function formaterTaille(int $octets): string
    {
        return $octets >= 1048576
            ? sprintf('%.1f Mo', $octets / 1048576)
            : sprintf('%.0f Ko', $octets / 1024);
    }
}

<?php declare(strict_types=1);

namespace App\Command;

use App\Repository\DirigeantRepository;
use App\Repository\DossierClubRepository;
use App\Service\Drive\DirigeantAttestationCleDriveSync;
use App\Service\Drive\DossierDriveSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:drive-retry-upload',
    description: 'Retente l\'upload Drive des PDFs en attente (stockés localement suite à une erreur Drive)',
)]
final class DriveRetryUploadCommand extends Command
{
    public function __construct(
        private readonly DossierClubRepository $dossierRepository,
        private readonly DossierDriveSync $driveSync,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DirigeantAttestationCleDriveSync $attestationCleDriveSync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dossiers = $this->dossierRepository->findWithLocalPdf();

        if (empty($dossiers)) {
            $io->info('Aucun règlement licencié en attente.');

            return $this->retryAttestationsCle($io) === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $io->info(sprintf('%d PDF(s) en attente d\'upload.', count($dossiers)));

        $success = 0;
        $failure = 0;
        $missing = 0;

        foreach ($dossiers as $dossier) {
            $licencie  = $dossier->getLicencie();
            $localPath = $dossier->getSignaturePath();
            $label     = sprintf('%s %s', $licencie->getNom(), $licencie->getPrenom());

            if ($localPath === null || !file_exists($localPath)) {
                $io->warning(sprintf('[%s] Fichier local introuvable : %s', $label, $localPath));
                $missing++;
                continue;
            }

            if ($this->driveSync->sync($dossier)) {
                $io->text(sprintf('<info>✓</info> [%s] Uploadé → %s', $label, $dossier->getSignaturePath()));
                $success++;
            } else {
                $io->text(sprintf('<comment>✗</comment> [%s] Échec, conservé en local.', $label));
                $failure++;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Uploadés'       => $success],
            ['Échecs'         => $failure],
            ['Fichier absent' => $missing],
        );

        $failure += $this->retryAttestationsCle($io);

        return $failure === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Attestations de remise de clés signées mais pas encore archivées sur Drive.
     * Le récapitulatif, lui, n'a rien à rattraper : il est régénéré depuis la base.
     */
    private function retryAttestationsCle(SymfonyStyle $io): int
    {
        $dirigeants = $this->dirigeantRepository->findWithLocalAttestationCle();

        if (empty($dirigeants)) {
            return 0;
        }

        $io->section(sprintf('%d attestation(s) de remise en attente d\'upload.', count($dirigeants)));

        $success = 0;
        $failure = 0;
        $missing = 0;

        foreach ($dirigeants as $dirigeant) {
            $localPath = $dirigeant->getAttestationCleSignePath();
            $label     = $dirigeant->getNomPrenom();

            if ($localPath === null || !file_exists($localPath)) {
                $io->warning(sprintf('[%s] Fichier local introuvable : %s', $label, $localPath));
                $missing++;
                continue;
            }

            if ($this->attestationCleDriveSync->sync($dirigeant)) {
                $io->text(sprintf('<info>✓</info> [%s] Attestation uploadée → %s', $label, $dirigeant->getAttestationCleSignePath()));
                $success++;
            } else {
                $io->text(sprintf('<comment>✗</comment> [%s] Échec, conservée en local.', $label));
                $failure++;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Attestations uploadées' => $success],
            ['Échecs'                => $failure],
            ['Fichier absent'        => $missing],
        );

        return $failure;
    }
}

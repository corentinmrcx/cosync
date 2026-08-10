<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\AttestationCle;
use App\Entity\DocumentSignature;
use App\Repository\AttestationCleRepository;
use App\Repository\DocumentSignatureRepository;
use App\Service\Drive\AttestationCleDriveSync;
use App\Service\Drive\DocumentSignatureDriveSync;
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
        private readonly DocumentSignatureRepository $signatureRepository,
        private readonly DocumentSignatureDriveSync $documentDriveSync,
        private readonly AttestationCleRepository $attestationCleRepository,
        private readonly AttestationCleDriveSync $attestationCleDriveSync,
    ) {
        parent::__construct();
    }

    /**
     * Chaque famille de documents est rattrapée indépendamment : l'échec de l'une
     * ne doit pas empêcher les autres de partir. Le récapitulatif des détenteurs de
     * clés, lui, n'a rien à rattraper — il est régénéré depuis la base.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Licenciés et dirigeants, règlements et chartes : une seule section, les
        // signatures de documents partageant désormais le même circuit d'archivage.
        $failures = $this->retrySection(
            $io,
            'document(s) signé(s)',
            $this->signatureRepository->findWithLocalPath(),
            static fn (DocumentSignature $s): string => sprintf(
                '%s %s — %s',
                $s->getNom(),
                $s->getPrenom(),
                $s->getDocument()->getTitre(),
            ),
            static fn (DocumentSignature $s): ?string => $s->getDrivePath(),
            fn (DocumentSignature $s): bool => $this->documentDriveSync->sync($s),
        );

        $failures += $this->retrySection(
            $io,
            'attestation(s) de remise de clés',
            $this->attestationCleRepository->findWithLocalPdf(),
            static fn (AttestationCle $a): string => $a->getDetenteur()->getNomPrenom(),
            static fn (AttestationCle $a): ?string => $a->getDrivePath(),
            fn (AttestationCle $a): bool => $this->attestationCleDriveSync->sync($a),
        );

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Rattrape une famille de documents : pour chacun, vérifie que le fichier local
     * existe toujours puis relance sa synchronisation Drive.
     * Retourne le nombre d'échecs d'upload.
     *
     * @param object[]                   $items
     * @param callable(object): string   $label libellé de la personne, pour la sortie console
     * @param callable(object): ?string  $path  chemin courant : local avant sync, ID Drive après
     * @param callable(object): bool     $sync  synchronisation effective
     */
    private function retrySection(
        SymfonyStyle $io,
        string $libelle,
        array $items,
        callable $label,
        callable $path,
        callable $sync,
    ): int {
        if ($items === []) {
            $io->info(sprintf('Aucun rattrapage : %s en attente.', $libelle));

            return 0;
        }

        $io->section(sprintf('%d %s en attente d\'upload.', count($items), $libelle));

        $success = 0;
        $failure = 0;
        $missing = 0;

        foreach ($items as $item) {
            $nom = $label($item);
            $localPath = $path($item);

            if ($localPath === null || !file_exists($localPath)) {
                $io->warning(sprintf('[%s] Fichier local introuvable : %s', $nom, $localPath));
                ++$missing;
                continue;
            }

            if ($sync($item)) {
                $io->text(sprintf('<info>✓</info> [%s] Uploadé → %s', $nom, $path($item)));
                ++$success;
            } else {
                $io->text(sprintf('<comment>✗</comment> [%s] Échec, conservé en local.', $nom));
                ++$failure;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Uploadés' => $success],
            ['Échecs' => $failure],
            ['Fichier absent' => $missing],
        );

        return $failure;
    }
}

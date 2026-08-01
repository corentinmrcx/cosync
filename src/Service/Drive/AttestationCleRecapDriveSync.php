<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Season;
use App\Service\ClubHouse\AttestationCleRecapService;
use App\Service\Pdf\AttestationCleRecapPdfService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Régénère le récapitulatif des détenteurs de clés et remplace celui déjà présent
 * sur Drive, dans {saison}/Club house/Clés/.
 *
 * Contrairement aux feuilles individuelles, ce document ne porte aucune donnée
 * irrécupérable : il est reconstruit depuis la base à chaque appel. Le fichier
 * temporaire est donc supprimé même si l'upload échoue — rien à rattraper.
 */
final class AttestationCleRecapDriveSync
{
    /** @var string[] */
    private const DRIVE_PATH = ['Club house', 'Clés'];

    private const FILENAME = 'Detenteurs des cles - Recapitulatif.pdf';

    public function __construct(
        private readonly AttestationCleRecapService $recapService,
        private readonly AttestationCleRecapPdfService $recapPdf,
        private readonly DriveUploaderService $driveUploader,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function sync(Season $season): bool
    {
        $binary = $this->recapPdf->generate($season, $this->recapService->buildRows($season));

        $dir = $this->projectDir . '/var/pdfs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpPath = sprintf('%s/attestation_cle_recap_%d.pdf', $dir, $season->getId());

        try {
            file_put_contents($tmpPath, $binary);

            return $this->driveUploader->replaceAtPath(
                $tmpPath,
                $season->getLabel(),
                self::DRIVE_PATH,
                self::FILENAME,
                'recap-attestation-cle-' . $season->getId(),
            ) !== null;
        } finally {
            @unlink($tmpPath);
        }
    }
}

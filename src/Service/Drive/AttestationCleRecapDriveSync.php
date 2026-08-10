<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Season;
use App\Service\Cle\AttestationCleRecapService;
use App\Service\Pdf\AttestationCleRecapPdfService;
use App\Service\Pdf\PdfStorage;

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
        private readonly PdfStorage $storage,
    ) {}

    public function sync(Season $season): bool
    {
        $cheminTemporaire = $this->storage->ecrire(
            sprintf('attestation_cle_recap_%d.pdf', $season->getId()),
            $this->recapPdf->generate($season, $this->recapService->buildRows($season)),
        );

        try {
            return $this->driveUploader->replaceAtPath(
                $cheminTemporaire,
                $season->getLabel(),
                self::DRIVE_PATH,
                self::FILENAME,
                'recap-attestation-cle-' . $season->getId(),
            ) !== null;
        } finally {
            @unlink($cheminTemporaire);
        }
    }
}

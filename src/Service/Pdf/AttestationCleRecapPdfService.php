<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\AttestationCleRecapRow;
use App\Entity\Season;

/**
 * Récapitulatif des détenteurs de clés et de l'état de leur signature, destiné à la mairie.
 *
 * Ne contient aucune image de signature : celle-ci ne figure que sur la feuille
 * individuelle archivée sur Drive.
 */
final class AttestationCleRecapPdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
    ) {}

    /** @param AttestationCleRecapRow[] $rows */
    public function generate(Season $season, array $rows): string
    {
        return $this->renderer->render('pdf/attestation_cle_recap.html.twig', [
            'rows' => $rows,
            'saisonLabel' => $season->getLabel(),
            'logoDataUrl' => $this->assets->logoClub(),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }
}

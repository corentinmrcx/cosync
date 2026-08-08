<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\AttestationCleRecapRow;
use App\Entity\Season;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Récapitulatif des détenteurs de clés et de l'état de leur signature, destiné à
 * la mairie. Retourne le contenu binaire — le contrôleur le stream, le sync Drive
 * l'écrit dans un fichier temporaire.
 *
 * Ne contient aucune image de signature : celle-ci ne figure que sur la feuille
 * individuelle archivée sur Drive.
 */
final class AttestationCleRecapPdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /** @param AttestationCleRecapRow[] $rows */
    public function generate(Season $season, array $rows): string
    {
        $logoPath = $this->projectDir . '/public/images/logo/logo.png';
        $html = $this->twig->render('pdf/attestation_cle_recap.html.twig', [
            'rows' => $rows,
            'saisonLabel' => $season->getLabel(),
            'logoDataUrl' => is_file($logoPath)
                ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))
                : null,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

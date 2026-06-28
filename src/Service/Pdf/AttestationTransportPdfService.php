<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\AttestationTransportData;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Génère le PDF de l'attestation de transport bénévole.
 * Réutilisable pour licenciés et dirigeants.
 */
final class AttestationTransportPdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * Génère le PDF et le sauvegarde dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     */
    public function generate(AttestationTransportData $data, string $nom, string $prenom, string $seasonLabel): string
    {
        $encode = fn(string $path): string => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));

        $html = $this->twig->render('pdf/attestation_transport.html.twig', [
            'data'            => $data,
            'nom'             => $nom,
            'prenom'          => $prenom,
            'seasonLabel'     => $seasonLabel,
            'signedAt'        => new \DateTimeImmutable(),
            'logoDataUrl'     => $encode($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $encode($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = $this->projectDir . '/var/pdfs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9_]/', '', $data->nomConducteur . '_' . $data->prenomConducteur);
        $path = $dir . '/TRANSPORT_' . $safeName . '_' . uniqid() . '.pdf';
        file_put_contents($path, $dompdf->output());

        return $path;
    }
}

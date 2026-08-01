<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Dirigeant;
use App\Entity\Season;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Attestation individuelle de remise de clés du club house,
 * signée par un détenteur de clés.
 */
final class AttestationClePdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * Génère l'attestation signée d'un dirigeant dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     */
    public function generateSignee(
        Dirigeant $dirigeant,
        string $signatureDataUrl,
        int $nbCles,
        ?\DateTimeImmutable $remiseLe = null,
    ): string {
        $html = $this->twig->render('pdf/attestation_cle_signee.html.twig', [
            'prenom'           => $dirigeant->getPrenom(),
            'nom'              => $dirigeant->getNom(),
            'season'           => $dirigeant->getSeason(),
            'nbCles'           => $nbCles,
            'remiseLe'         => $remiseLe,
            'signatureDataUrl' => $signatureDataUrl,
            'signedAt'         => new \DateTimeImmutable(),
            'logoDataUrl'      => $this->encodeImage($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
        ]);

        $dir = $this->projectDir . '/var/pdfs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $dirigeant->getUuid() . '_attestation_cle.pdf';
        file_put_contents($path, $this->renderPdf($html));

        return $path;
    }

    /** Aperçu admin sans signature — retourne le binaire du PDF. */
    public function generatePreview(Season $season): string
    {
        $html = $this->twig->render('pdf/attestation_cle_signee.html.twig', [
            'prenom'           => 'Prénom',
            'nom'              => 'NOM',
            'season'           => $season,
            'nbCles'           => 1,
            'remiseLe'         => new \DateTimeImmutable(),
            'signatureDataUrl' => '',
            'signedAt'         => new \DateTimeImmutable(),
            'logoDataUrl'      => $this->encodeImage($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
            'previewMode'      => true,
        ]);

        return $this->renderPdf($html);
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function encodeImage(string $path): string
    {
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }
}

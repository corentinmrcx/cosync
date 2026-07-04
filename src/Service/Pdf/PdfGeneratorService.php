<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class PdfGeneratorService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * Génère le PDF règlement signé d'un licencié et le sauvegarde dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     */
    public function generateReglementSigne(Licencie $licencie, string $signatureDataUrl): string
    {
        return $this->renderReglementToFile(
            $licencie->getPrenom(),
            $licencie->getNom(),
            $licencie->getSeason(),
            $signatureDataUrl,
            (string) $licencie->getUuid(),
        );
    }

    /**
     * Génère le PDF règlement signé d'un dirigeant et le sauvegarde dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     */
    public function generateReglementSigneDirigeant(Dirigeant $dirigeant, string $signatureDataUrl): string
    {
        return $this->renderReglementToFile(
            $dirigeant->getPrenom(),
            $dirigeant->getNom(),
            $dirigeant->getSeason(),
            $signatureDataUrl,
            (string) $dirigeant->getUuid(),
        );
    }

    public function generatePreview(Season $season): string
    {
        $html = $this->twig->render('pdf/reglement_signe.html.twig', [
            'prenom'           => 'Prénom',
            'nom'              => 'NOM',
            'season'           => $season,
            'signatureDataUrl' => '',
            'signedAt'         => new \DateTimeImmutable(),
            'logoDataUrl'      => $this->encodeImage($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
            'previewMode'      => true,
        ]);

        return $this->renderPdf($html);
    }

    /** Rend le règlement signé en PDF et l'écrit dans var/pdfs/. Retourne le chemin absolu. */
    private function renderReglementToFile(string $prenom, string $nom, Season $season, string $signatureDataUrl, string $fileKey): string
    {
        $html = $this->twig->render('pdf/reglement_signe.html.twig', [
            'prenom'           => $prenom,
            'nom'              => $nom,
            'season'           => $season,
            'signatureDataUrl' => $signatureDataUrl,
            'signedAt'         => new \DateTimeImmutable(),
            'logoDataUrl'      => $this->encodeImage($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
        ]);

        $dir = $this->projectDir . '/var/pdfs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $fileKey . '_reglement.pdf';
        file_put_contents($path, $this->renderPdf($html));

        return $path;
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
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }
}

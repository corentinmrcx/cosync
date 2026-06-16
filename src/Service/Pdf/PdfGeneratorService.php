<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Licencie;
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
     * Génère le PDF règlement signé et le sauvegarde dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     */
    public function generateReglementSigne(Licencie $licencie, string $signatureDataUrl): string
    {
        $encode = fn(string $path): string => 'data:image/png;base64,' . base64_encode(file_get_contents($path));

        $html = $this->twig->render('pdf/reglement_signe.html.twig', [
            'licencie'          => $licencie,
            'season'            => $licencie->getSeason(),
            'signatureDataUrl'  => $signatureDataUrl,
            'signedAt'          => new \DateTimeImmutable(),
            'logoDataUrl'       => $encode($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl'  => $encode($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
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

        $path = $dir . '/' . ((string) $licencie->getUuid()) . '_reglement.pdf';
        file_put_contents($path, $dompdf->output());

        return $path;
    }
}

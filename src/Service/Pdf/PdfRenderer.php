<?php declare(strict_types=1);

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Rend un template Twig en PDF.
 *
 * Les ressources distantes sont désactivées : un PDF ne doit jamais dépendre du réseau
 * au moment où il est produit — les images sont donc embarquées en base64 (AssetEncoder).
 */
final class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * @param array<string, mixed> $contexte
     *
     * @return string contenu binaire du PDF
     */
    public function render(string $template, array $contexte): string
    {
        $html = $this->twig->render($template, $contexte);

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

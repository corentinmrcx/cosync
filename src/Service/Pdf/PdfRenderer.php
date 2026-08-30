<?php declare(strict_types=1);

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Rend un template Twig en PDF.
 *
 * Les ressources distantes sont désactivées : un PDF ne doit jamais dépendre du réseau
 * au moment où il est produit — les images sont donc embarquées en base64 (AssetEncoder)
 * et les polices lues sur le disque.
 */
final class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * @param array<string, mixed> $contexte
     * @param string               $papier      format DomPDF : 'A4', 'A5'…
     * @param string               $orientation 'portrait' ou 'landscape'
     *
     * @return string contenu binaire du PDF
     */
    public function render(string $template, array $contexte, string $papier = 'A4', string $orientation = 'portrait'): string
    {
        // Chemin des polices embarquées, à disposition des templates qui déclarent une
        // @font-face. Passé par le contexte plutôt qu'écrit en dur : le chemin absolu
        // diffère entre le poste de travail et le conteneur de production.
        $html = $this->twig->render($template, $contexte + ['fontDir' => $this->projectDir . '/public/fonts']);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');
        // `isRemoteEnabled` étant à false, DomPDF n'accepte de lire un fichier local que
        // s'il se trouve sous la racine autorisée. Sans ça, les @font-face des templates
        // échouent en silence et le document retombe sur Arial.
        $options->set('chroot', [$this->projectDir . '/public']);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        // Les valeurs par défaut reproduisent l'A4 portrait d'avant : les documents
        // existants (attestations, bons de commande, inventaire) n'ont rien à changer.
        $dompdf->setPaper($papier, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

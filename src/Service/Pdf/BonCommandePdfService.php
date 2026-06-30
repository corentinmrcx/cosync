<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Commande;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Génère le PDF d'un bon de commande fournisseur (quantités seules, pas de coût).
 * Retourne le contenu binaire — le contrôleur le stream en téléchargement.
 */
final class BonCommandePdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function generate(Commande $commande): string
    {
        $logoPath = $this->projectDir . '/public/images/logo/logo.png';
        $html = $this->twig->render('pdf/bon_commande.html.twig', [
            'commande'    => $commande,
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

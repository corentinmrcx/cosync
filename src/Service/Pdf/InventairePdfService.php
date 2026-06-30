<?php declare(strict_types=1);

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Génère la feuille d'inventaire du stock (état théorique + colonne de comptage
 * physique à remplir à la main). Retourne le contenu binaire — le contrôleur le stream.
 */
final class InventairePdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $inventaire
     */
    public function generate(array $inventaire, ?string $saisonLabel): string
    {
        $logoPath = $this->projectDir . '/public/images/logo/logo.png';
        $html = $this->twig->render('pdf/inventaire.html.twig', [
            'inventaire'  => $inventaire,
            'saisonLabel' => $saisonLabel,
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

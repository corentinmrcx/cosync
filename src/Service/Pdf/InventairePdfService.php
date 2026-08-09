<?php declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Feuille d'inventaire du stock : état théorique, plus une colonne de comptage physique
 * à remplir à la main.
 */
final class InventairePdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
    ) {}

    /** @param array<int, array<string, mixed>> $inventaire */
    public function generate(array $inventaire, ?string $saisonLabel): string
    {
        return $this->renderer->render('pdf/inventaire.html.twig', [
            'inventaire' => $inventaire,
            'saisonLabel' => $saisonLabel,
            'logoDataUrl' => $this->assets->logoClub(),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }
}

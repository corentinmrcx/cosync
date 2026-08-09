<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\Stock\StockInventaireLigne;
use App\DTO\Stock\StockSection;

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

    /** @param list<StockSection<StockInventaireLigne>> $inventaire */
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

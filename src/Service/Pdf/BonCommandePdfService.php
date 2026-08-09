<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Commande;

/**
 * Bon de commande fournisseur (quantités seules, pas de coût).
 */
final class BonCommandePdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
    ) {}

    public function generate(Commande $commande): string
    {
        return $this->renderer->render('pdf/bon_commande.html.twig', [
            'commande' => $commande,
            'logoDataUrl' => $this->assets->logoClub(),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }
}

<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Commande;
use App\Service\Stock\BonCommandePresenter;

/**
 * Bon de commande fournisseur (quantités seules, pas de coût).
 *
 * Il sert à ne pas lister les quantités à la main : c'est le fournisseur qui édite le devis,
 * et tout ce qu'on écrirait ici de plus — coordonnées, prix, mentions — s'y retrouverait,
 * en risquant de le contredire.
 */
final class BonCommandePdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly BonCommandePresenter $presenter,
    ) {}

    public function generate(Commande $commande): string
    {
        return $this->renderer->render('pdf/bon_commande.html.twig', [
            'commande' => $commande,
            'articles' => $this->presenter->articles($commande),
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }
}

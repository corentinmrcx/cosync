<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\Justificatif\JustificatifAchat;

/**
 * Justificatif d'achat des dotations : la pièce qu'on agrafe au devis avant de le porter au
 * conseil. Quantités seules — les prix sont au devis.
 */
final class JustificatifAchatPdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
    ) {}

    public function generate(JustificatifAchat $doc): string
    {
        return $this->renderer->render('pdf/justificatif_achat.html.twig', [
            'doc' => $doc,
            // Les deux logos, comme tout document qui sort du club : l'en-tête commun les
            // place de part et d'autre du titre, et n'en passer qu'un le laissait bancal.
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
        ]);
    }

    /** Nom daté : une seconde demande dans la saison ne remplace pas la première. */
    public function nomFichier(JustificatifAchat $doc): string
    {
        return sprintf('justificatif_achat_dotations_%s.pdf', $doc->editeLe->format('Y-m-d'));
    }
}

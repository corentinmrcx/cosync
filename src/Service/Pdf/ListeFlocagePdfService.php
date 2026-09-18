<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\Flocage\ListeFlocage;

/**
 * La liste de flocage remise au floqueur : article, référence, taille, texte à marquer.
 *
 * Document de travail, pas pièce d'archive : il se réédite à volonté et ne monte pas sur le
 * Drive — contrairement au justificatif d'achat, qui se classe parce qu'il a été présenté.
 */
final class ListeFlocagePdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
    ) {}

    public function generate(ListeFlocage $doc): string
    {
        return $this->renderer->render('pdf/liste_flocage.html.twig', [
            'doc' => $doc,
            // Les deux logos, comme tout document qui sort du club.
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
        ]);
    }

    /** Nom daté : le club en réédite une à chaque vague de licences, sans écraser la première. */
    public function nomFichier(ListeFlocage $doc): string
    {
        return sprintf('liste_flocage_%s.pdf', $doc->editeLe->format('Y-m-d'));
    }
}

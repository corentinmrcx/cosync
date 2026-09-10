<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Season;
use App\Enum\UsageOccupation;
use App\Service\Occupation\OccupationGrillePresenter;
use App\Service\Referentiel\ClubSettingsService;

/**
 * La grille d'occupation des terrains, telle qu'elle est remise à la mairie.
 *
 * **A4 paysage** : sept colonnes de jours sur une feuille portrait donneraient des colonnes
 * de trois centimètres, où « Terrain d'entraînement 1 » ne tient pas.
 *
 * Le placement des blocs vient de {@see \App\Service\Occupation\OccupationGrilleGeometrie},
 * le même qui sert à l'écran d'administration : ce document montre exactement la grille que
 * l'admin a composée. La géométrie rend des fractions ; c'est ici qu'elles deviennent des
 * millimètres, parce que c'est ici qu'on connaît la feuille.
 *
 * ⚠️ **L'horizontal est en pourcentages, le vertical en millimètres.** Les colonnes se
 * partagent la largeur du corps du document — la même que celle de l'en-tête à deux logos,
 * quoi que fassent les marges de page —, tandis que les hauteurs doivent être écrites, faute
 * de quoi DomPDF n'a aucune hauteur de référence pour un bloc positionné en absolu. C'est
 * une largeur supposée en millimètres qui faisait sortir la grille plus étroite que le filet
 * de l'en-tête, avec du blanc perdu sur la droite.
 */
final class OccupationPdfService
{
    /**
     * Colonne des heures, à gauche des sept jours, en % de la largeur du corps.
     *
     * Réglée sur la **largeur réelle du plus long libellé** (« 22h ») plus le blanc qui le
     * sépare de la grille — rien de plus. C'est ce qui centre l'emploi du temps sur la
     * feuille : les heures en font partie, et tout couloir plus large qu'elles se serait
     * ajouté à la marge de gauche sans rien mettre en face à droite. Le décalage se voyait à
     * l'œil nu sur un couloir de onze millimètres pour trois de texte.
     */
    private const LARGEUR_AXE = 2.45;

    /** Bande des noms de jours, au-dessus de la grille. */
    private const HAUTEUR_ENTETE = 7.0;

    /**
     * Hauteur de la grille, en millimètres.
     *
     * Une constante, et elle peut l'être parce que **rien de variable ne suit la grille** :
     * la légende fait une ligne, quel que soit le club. Le bilan des heures y figurait, et
     * comme il grandit d'un terrain à l'autre, aucune hauteur fixe ne pouvait garder le
     * blanc du haut égal à celui du bas — il a été retiré du document, il reste à l'écran.
     *
     * Mesurée sur un rendu à 300 dpi : la grille et sa légende tombent au milieu de l'espace
     * laissé entre le filet de l'en-tête et celui du pied.
     */
    private const HAUTEUR_GRILLE = 137.2;

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly OccupationGrillePresenter $presenter,
        private readonly AssetEncoder $assets,
        private readonly ClubSettingsService $clubSettings,
    ) {}

    /** @return string contenu binaire du PDF */
    public function rendu(Season $season): string
    {
        return $this->renderer->render(
            'pdf/occupation/grille.html.twig',
            [
                'grille' => $this->presenter->grille($season),
                'usages' => UsageOccupation::cases(),
                'season' => $season,
                'club' => $this->clubSettings->get(),
                'logoDataUrl' => $this->assets->logoClub(),
                'foyerLogoDataUrl' => $this->assets->logoFoyer(),
                'genereLe' => new \DateTimeImmutable(),
                // Les cotes de la feuille, pour que le template n'ait qu'à multiplier.
                'largeurAxe' => self::LARGEUR_AXE,
                'largeurColonne' => round((100 - self::LARGEUR_AXE) / 7, 4),
                'hauteurEntete' => self::HAUTEUR_ENTETE,
                'hauteurGrille' => self::HAUTEUR_GRILLE,
            ],
            'A4',
            'landscape',
        );
    }

    /**
     * `occupation_terrains_2026-2027.pdf` — la saison suffit à le nommer : contrairement au
     * planning des matchs, il n'y en a qu'un par saison, et le régénérer remplace le
     * précédent au même endroit sur le Drive.
     */
    public function nomFichier(Season $season): string
    {
        return sprintf('occupation_terrains_%s.pdf', $season->getLabel());
    }
}

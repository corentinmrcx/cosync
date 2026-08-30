<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\Planning\PlanningPeriode;
use App\Entity\Season;
use App\Enum\PlanningFormat;
use App\Service\Planning\PlanningDocumentPresenter;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Ui\DateFrancaiseFormatter;

/**
 * Produit le planning des matchs à domicile dans l'un des trois tirages.
 *
 * Les trois passent par la **même donnée** et le même contexte : seuls le template, le
 * format papier et l'orientation changent. C'est ce qui garantit que le flyer distribué
 * dans les boîtes aux lettres et la feuille remise à la mairie annoncent les mêmes
 * matchs — deux chemins de données séparés finiraient par diverger d'un report.
 */
final class PlanningPdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly PlanningDocumentPresenter $presenter,
        private readonly FlyerGeometrie $geometrie,
        private readonly AssetEncoder $assets,
        private readonly ClubSettingsService $clubSettings,
        private readonly DateFrancaiseFormatter $dates,
    ) {}

    /** @return string contenu binaire du PDF */
    public function rendu(Season $season, PlanningPeriode $periode, PlanningFormat $format): string
    {
        $matchs = $this->presenter->matchs($season, $periode);
        $omis = 0;
        $piedTop = null;

        // Le flyer tient sur une page : la géométrie décide de ce qui y entre et à quelle
        // hauteur tombe le bloc de contacts. La feuille de la mairie prend tout et
        // continue sur une seconde page.
        if ($format->estFlyer()) {
            $ajuste = $this->geometrie->ajuster($matchs);
            $matchs = $ajuste['matchs'];
            $omis = $ajuste['omis'];
            $piedTop = $ajuste['piedTop'];
        }

        return $this->renderer->render(
            $format->template(),
            [
                'journees' => $this->presenter->grouper($matchs),
                'omis' => $omis,
                'piedTop' => $piedTop,
                'periode' => $periode,
                'periodeLibelle' => $this->dates->periode($periode->du, $periode->au),
                'season' => $season,
                'club' => $this->clubSettings->get(),
                'logoDataUrl' => $this->assets->logoClub(),
                'foyerLogoDataUrl' => $this->assets->logoFoyer(),
                'genereLe' => new \DateTimeImmutable(),
            ],
            $format->papier(),
            $format->orientation(),
        );
    }

    /**
     * Nom du fichier, identique au téléchargement et à l'archivage Drive : c'est ce qui
     * permet de retrouver sur le Drive le document exact qu'on a distribué.
     *
     * `matchs_domicile_2026-2027_01-09-2026_flyer-duo.pdf` — la saison, puis le premier
     * jour de la période en jour-mois-année. Les tirets et non les barres obliques : une
     * date écrite `01/09/2026` ouvrirait des dossiers dans le chemin du fichier.
     */
    public function nomFichier(Season $season, PlanningPeriode $periode, PlanningFormat $format): string
    {
        return sprintf(
            'matchs_domicile_%s_%s_%s.pdf',
            $season->getLabel(),
            $periode->du->format('d-m-Y'),
            $format->suffixeFichier(),
        );
    }
}

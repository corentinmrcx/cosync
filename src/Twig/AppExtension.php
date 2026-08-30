<?php declare(strict_types=1);

namespace App\Twig;

use App\Enum\TailleType;
use App\Repository\SeasonRepository;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Referentiel\TailleReferentiel;
use App\Service\Saison\SeasonContext;
use App\Service\Ui\DateFrancaiseFormatter;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly SeasonRepository $seasonRepository,
        private readonly ClubSettingsService $clubSettings,
        private readonly TailleReferentiel $tailles,
        private readonly DateFrancaiseFormatter $dates,
        private readonly string $nomClub,
    ) {}

    public function getGlobals(): array
    {
        $season = $this->seasonContext->getCurrentSeason();
        $club = $this->clubSettings->get();

        return [
            'club_nom' => $this->nomClub,
            // Maillon « saison » du fil d'Ariane. null quand aucune saison n'existe : le
            // composant breadcrumb saute alors le maillon au lieu de rompre le rendu.
            'navbar_saison_label' => $season !== null ? 'Saison ' . $season->getLabel() : null,
            // Coordonnées bancaires du club : lues par le formulaire public, la page de
            // confirmation et le mail de confirmation, rendus depuis trois contextes différents.
            'club_rib' => $club,
            // Lien de la boutique du club, ou null tant qu'elle n'est pas ouverte : les écrans
            // et les mails qui l'annoncent se taisent alors au lieu d'afficher un lien mort —
            // ou un lien préparé d'avance, avant que le club veuille en parler.
            'club_boutique_url' => $club->getBoutiqueUrlPublique(),
            'navbar_current_season' => $this->seasonContext->getCurrentSeason(),
            'navbar_seasons' => $this->seasonRepository->findBy([], ['createdAt' => 'DESC']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('phone_format', $this->formatPhone(...)),
            // Dates en français. Le filtre Twig `date` ne sait pas traduire, et
            // `format_datetime` s'appuierait sur un ICU réduit à l'anglais dans cette
            // image — cf. DateFrancaiseFormatter.
            new TwigFilter('date_fr', $this->dates->complete(...)),
            new TwigFilter('date_fr_jour_mois', $this->dates->jourEtMois(...)),
            new TwigFilter('date_fr_courte', $this->dates->court(...)),
            new TwigFilter('date_fr_sans_jour', $this->dates->sansJour(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // Groupes proposés aux licenciés et aux dirigeants, tels qu'ils s'affichent
            // dans les sélecteurs : intitulés et ordre viennent du référentiel admin.
            new TwigFunction('tailles_groupes', fn (): array => $this->tailles->groupesProposes(TailleType::VETEMENT)),
            new TwigFunction('pointures_groupes', fn (): array => $this->tailles->groupesProposes(TailleType::POINTURE)),
            // Ordre d'affichage complet, tailles réservées au stock comprises : la modale
            // de mouvement s'en sert pour ranger les déclinaisons d'un article.
            new TwigFunction('tailles_ordre', fn (): array => array_merge(
                $this->tailles->pourLeStock(TailleType::VETEMENT),
                $this->tailles->pourLeStock(TailleType::POINTURE),
            )),
        ];
    }

    public function formatPhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '+33') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }

        // 9 chiffres sans zéro initial (stockés tels quels depuis FootClubs)
        if (strlen($digits) === 9 && preg_match('/^[67]/', $digits)) {
            $digits = '0' . $digits;
        }

        if (strlen($digits) === 10) {
            return implode(' ', str_split($digits, 2));
        }

        return $phone;
    }
}

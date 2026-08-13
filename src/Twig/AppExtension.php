<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\SeasonRepository;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Referentiel\Tailles;
use App\Service\Saison\SeasonContext;
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
            // Lien de la boutique du club, ou null tant qu'aucun n'est saisi : les écrans
            // et les mails qui l'annoncent se taisent alors au lieu d'afficher un lien mort.
            'club_boutique_url' => $club->getBoutiqueUrl(),
            'navbar_current_season' => $this->seasonContext->getCurrentSeason(),
            'navbar_seasons' => $this->seasonRepository->findBy([], ['createdAt' => 'DESC']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('phone_format', $this->formatPhone(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tailles_adulte', static fn (): array => Tailles::ADULTE),
            new TwigFunction('tailles_enfant', static fn (): array => Tailles::ENFANT),
            new TwigFunction('tailles_toutes', Tailles::toutes(...)),
            new TwigFunction('pointures', Tailles::pointures(...)),
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

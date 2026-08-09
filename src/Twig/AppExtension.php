<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\SeasonRepository;
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
        private readonly string $nomClub,
    ) {}

    public function getGlobals(): array
    {
        return [
            'club_nom' => $this->nomClub,
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

<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\SeasonRepository;
use App\Service\SeasonContext;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly SeasonRepository $seasonRepository,
    ) {}

    public function getGlobals(): array
    {
        return [
            'navbar_current_season' => $this->seasonContext->getCurrentSeason(),
            'navbar_seasons'        => $this->seasonRepository->findBy([], ['createdAt' => 'DESC']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('phone_format', $this->formatPhone(...)),
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

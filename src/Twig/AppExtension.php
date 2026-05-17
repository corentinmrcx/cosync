<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\SeasonRepository;
use App\Service\SeasonContext;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

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
}

<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class SeasonContext
{
    private const SESSION_KEY = 'admin_selected_season_id';

    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly RequestStack $requestStack,
    ) {}

    public function getCurrentSeason(): ?Season
    {
        $session  = $this->requestStack->getSession();
        $seasonId = $session->get(self::SESSION_KEY);

        if ($seasonId) {
            $season = $this->seasonRepository->find($seasonId);
            if ($season) {
                return $season;
            }
        }

        return $this->seasonRepository->findMostRecent();
    }

    public function setCurrentSeason(Season $season): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $season->getId());
    }
}

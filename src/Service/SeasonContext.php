<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Entity\User;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class SeasonContext
{
    private const SESSION_KEY = 'admin_selected_season_id';

    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrentSeason(): ?Season
    {
        $user = $this->security->getUser();
        if ($user instanceof User && $user->getSelectedSeason() !== null) {
            return $user->getSelectedSeason();
        }

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

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $user->setSelectedSeason($season);
            $this->em->flush();
        }
    }
}

<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SeasonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonRepository $seasonRepository,
    ) {}

    public function create(Season $season): void
    {
        if ($season->isActive()) {
            $this->deactivateAll();
        }

        $this->em->persist($season);
        $this->em->flush();
    }

    public function update(Season $season): void
    {
        if ($season->isActive()) {
            $this->deactivateAllExcept($season);
        }

        $this->em->flush();
    }

    public function activate(Season $season): void
    {
        $this->deactivateAllExcept($season);
        $season->setActive(true);
        $this->em->flush();
    }

    private function deactivateAll(): void
    {
        foreach ($this->seasonRepository->findBy(['active' => true]) as $s) {
            $s->setActive(false);
        }
    }

    private function deactivateAllExcept(Season $except): void
    {
        foreach ($this->seasonRepository->findBy(['active' => true]) as $s) {
            if ($s->getId() !== $except->getId()) {
                $s->setActive(false);
            }
        }
    }
}

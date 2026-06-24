<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;

final class SeasonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function create(Season $season): void
    {
        $this->em->persist($season);
        $this->em->flush();
    }

    public function update(Season $season): void
    {
        $this->em->flush();
    }

    public function updateReglement(Season $season, ?string $reglementText): void
    {
        $season->setReglementText($reglementText);
        $this->em->flush();
    }

    public function delete(Season $season): void
    {
        $this->em->remove($season);
        $this->em->flush();
    }
}

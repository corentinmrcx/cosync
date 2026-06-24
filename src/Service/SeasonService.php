<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SeasonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonRepository $seasonRepo,
    ) {}

    public function create(Season $season): void
    {
        if ($this->seasonRepo->existsByLabel($season->getLabel())) {
            throw new \DomainException(sprintf('La saison "%s" existe déjà.', $season->getLabel()));
        }

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

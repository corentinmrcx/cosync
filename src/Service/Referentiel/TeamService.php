<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\DTO\TeamEditData;
use App\DTO\TeamSetupData;
use App\Entity\Season;
use App\Entity\Team;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TeamService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepository,
    ) {}

    /** @throws \DomainException si le nom est vide */
    public function creer(TeamSetupData $data, Season $season): Team
    {
        $nom = trim($data->name);
        if ($nom === '') {
            throw new \DomainException('Le nom de l\'équipe est obligatoire.');
        }

        $team = (new Team())
            ->setName($nom)
            ->setSeason($season)
            ->setCotisation($data->cotisation);

        foreach ($data->categories as $category) {
            $team->addCategory($category);
        }

        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @throws \DomainException si le nom est vide */
    public function mettreAJour(Team $team, TeamEditData $data): void
    {
        if ($data->nom === null) {
            throw new \DomainException('Le nom ne peut pas être vide.');
        }

        $team->setName($data->nom);
        $team->setCotisation($data->cotisation);

        $team->getCategories()->clear();
        foreach ($data->categoryIds as $categoryId) {
            $category = $this->categoryRepository->find($categoryId);
            if ($category !== null) {
                $team->addCategory($category);
            }
        }

        $this->em->flush();
    }

    public function supprimer(Team $team): void
    {
        $this->em->remove($team);
        $this->em->flush();
    }
}

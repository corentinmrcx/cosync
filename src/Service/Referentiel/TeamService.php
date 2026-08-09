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

    /**
     * Une équipe naît sans cotisation propre : elle suit la cotisation par défaut de la
     * saison tant que l'écran « Cotisations » ne lui en attribue pas une.
     *
     * @throws \DomainException si le nom est vide
     */
    public function creer(TeamSetupData $data, Season $season): Team
    {
        $nom = trim($data->name);
        if ($nom === '') {
            throw new \DomainException('Le nom de l\'équipe est obligatoire.');
        }

        $team = (new Team())
            ->setName($nom)
            ->setSeason($season);

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

        $team->getCategories()->clear();
        foreach ($data->categoryIds as $categoryId) {
            $category = $this->categoryRepository->find($categoryId);
            if ($category !== null) {
                $team->addCategory($category);
            }
        }

        $this->em->flush();
    }

    /** null = l'équipe suit la cotisation par défaut de la saison. */
    public function definirCotisation(Team $team, ?int $cotisation): void
    {
        if ($cotisation !== null && $cotisation < 0) {
            throw new \DomainException('Une cotisation ne peut pas être négative.');
        }

        $team->setCotisation($cotisation);
        $this->em->flush();
    }

    public function supprimer(Team $team): void
    {
        $this->em->remove($team);
        $this->em->flush();
    }
}

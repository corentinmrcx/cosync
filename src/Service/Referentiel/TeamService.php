<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\DTO\TeamEditData;
use App\DTO\TeamSetupData;
use App\Entity\Season;
use App\Entity\Team;
use App\Repository\CategoryRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TeamService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
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

    /**
     * Fixe en un seul envoi la cotisation de chaque équipe de la saison.
     *
     * On parcourt les équipes de la saison, pas les clés reçues : un id d'équipe étranger à
     * la saison est ainsi ignoré sans contrôle supplémentaire, et une équipe absente du POST
     * garde sa cotisation. Tout est validé avant la première écriture pour qu'une saisie
     * fautive ne laisse pas la moitié de la liste enregistrée.
     *
     * @param array<array-key, mixed> $saisies cotisation brute indexée par id d'équipe ;
     *                                         chaîne vide = l'équipe suit le défaut de la saison
     *
     * @throws \DomainException si un montant est invalide — dans ce cas rien n'est écrit
     */
    public function definirCotisations(Season $season, array $saisies): void
    {
        /** @var \SplObjectStorage<Team, ?int> $aEcrire */
        $aEcrire = new \SplObjectStorage();

        foreach ($this->teamRepository->findBySeason($season) as $team) {
            $cle = (string) $team->getId();
            if (!array_key_exists($cle, $saisies)) {
                continue;
            }

            $aEcrire[$team] = $this->normaliserCotisation($saisies[$cle], $team);
        }

        foreach ($aEcrire as $team) {
            $team->setCotisation($aEcrire[$team]);
        }

        $this->em->flush();
    }

    /**
     * @throws \DomainException si la saisie n'est pas un entier positif ou une chaîne vide
     */
    private function normaliserCotisation(mixed $saisie, Team $team): ?int
    {
        if (!is_scalar($saisie)) {
            throw new \DomainException(sprintf('Cotisation illisible pour "%s".', $team->getName()));
        }

        $saisie = trim((string) $saisie);
        if ($saisie === '') {
            return null;
        }

        if (!ctype_digit($saisie)) {
            throw new \DomainException(sprintf('La cotisation de "%s" doit être un montant entier positif.', $team->getName()));
        }

        return (int) $saisie;
    }

    public function supprimer(Team $team): void
    {
        $this->em->remove($team);
        $this->em->flush();
    }
}

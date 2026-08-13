<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Season;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /** @return Team[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.categories', 'c')
            ->addSelect('c')
            ->where('t.season = :season')
            ->setParameter('season', $season)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Team[] */
    public function findByCategory(Category $category): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.categories', 'c')
            ->where('c = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getResult();
    }

    /**
     * Équipe unique de chaque catégorie de la saison, indexée par id de catégorie.
     *
     * Même règle que findForCategory(), mais résolue en une requête : une catégorie
     * couverte par deux équipes (« U15 A » et « U15 B ») est absente du tableau, aucune
     * règle ne permettant de trancher.
     *
     * @return array<int, Team>
     */
    public function mapCategorieVersEquipeUnique(Season $season): array
    {
        $uniques = [];
        $ambigues = [];

        foreach ($this->findBySeason($season) as $team) {
            foreach ($team->getCategories() as $category) {
                $categoryId = (int) $category->getId();

                if (isset($uniques[$categoryId])) {
                    $ambigues[$categoryId] = true;

                    continue;
                }

                $uniques[$categoryId] = $team;
            }
        }

        return array_diff_key($uniques, $ambigues);
    }

    /**
     * Retourne l'équipe unique ayant cette catégorie dans la saison.
     * Si plusieurs équipes la partagent, retourne null (ambiguïté).
     */
    public function findForCategory(Category $category, Season $season): ?Team
    {
        $teams = $this->createQueryBuilder('t')
            ->join('t.categories', 'c')
            ->where('c = :category')
            ->andWhere('t.season = :season')
            ->setParameter('category', $category)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();

        return count($teams) === 1 ? $teams[0] : null;
    }
}

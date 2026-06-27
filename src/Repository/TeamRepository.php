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

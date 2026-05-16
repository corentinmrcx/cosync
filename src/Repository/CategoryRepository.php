<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findByBirthYear(int $year): ?Category
    {
        return $this->createQueryBuilder('c')
            ->where('c.minYear <= :year AND c.maxYear >= :year')
            ->setParameter('year', $year)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

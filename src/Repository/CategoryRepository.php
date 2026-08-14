<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Service\Referentiel\CategoryOrdre;
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

    /**
     * Toutes les catégories dans l'ordre des âges (cf. CategoryOrdre).
     *
     * Le tri se fait en PHP : le rang se déduit du code, pas d'une colonne.
     *
     * @return list<Category>
     */
    public function findAllOrdered(): array
    {
        return CategoryOrdre::trier($this->findAll());
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

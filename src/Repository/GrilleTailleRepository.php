<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\GrilleTaille;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GrilleTaille>
 */
class GrilleTailleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrilleTaille::class);
    }

    /** @return GrilleTaille[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.type', 'ASC')
            ->addOrderBy('g.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Articles rattachés, par identifiant de grille — une requête pour toute la liste.
     *
     * @return array<int, int>
     */
    public function compterArticlesParGrille(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(i.grilleTaille) AS grille, COUNT(i.id) AS total
             FROM App\Entity\StockItem i
             WHERE i.grilleTaille IS NOT NULL
             GROUP BY i.grilleTaille',
        )->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['grille']] = (int) $row['total'];
        }

        return $out;
    }
}

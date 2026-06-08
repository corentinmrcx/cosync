<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\StockItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockItem>
 */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /** @return StockItem[] Uniquement les articles avec typeVetement renseigné */
    public function findVetementsBySeason(Season $season): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.season = :season')
            ->andWhere('i.typeVetement IS NOT NULL')
            ->andWhere('i.taille IS NOT NULL')
            ->setParameter('season', $season)
            ->orderBy('i.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return StockItem[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.category', 'c')
            ->addSelect('c')
            ->where('i.season = :season')
            ->setParameter('season', $season)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('i.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

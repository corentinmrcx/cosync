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

    /** @return StockItem[] */
    public function findBySeason(Season $season): array
    {
        return $this->findBy(['season' => $season], ['nom' => 'ASC']);
    }
}

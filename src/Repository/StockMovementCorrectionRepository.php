<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockMovementCorrection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockMovementCorrection>
 */
class StockMovementCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovementCorrection::class);
    }
}

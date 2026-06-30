<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DotationModeleLigne;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DotationModeleLigne>
 */
class DotationModeleLigneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DotationModeleLigne::class);
    }
}

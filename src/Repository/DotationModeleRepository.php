<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DotationModele;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DotationModele>
 */
class DotationModeleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DotationModele::class);
    }

    /** @return DotationModele[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.season = :season')
            ->setParameter('season', $season)
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DotationAffectation;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DotationAffectation>
 */
class DotationAffectationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DotationAffectation::class);
    }

    /** @return DotationAffectation[] Toutes les affectations de la saison, jointures préchargées. */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.modele', 'm')->addSelect('m')
            ->leftJoin('a.category', 'c')->addSelect('c')
            ->leftJoin('a.team', 't')->addSelect('t')
            ->leftJoin('a.licencie', 'l')->addSelect('l')
            ->leftJoin('a.dirigeant', 'd')->addSelect('d')
            ->where('a.season = :season')
            ->setParameter('season', $season)
            // Ordre stable : à priorité égale, DotationResolver retient la dernière affectation
            // créée. Sans ce tri, le gagnant dépendrait du plan d'exécution SQL.
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

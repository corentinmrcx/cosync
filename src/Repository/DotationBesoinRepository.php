<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DotationBesoin>
 */
class DotationBesoinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DotationBesoin::class);
    }

    /** @return DotationBesoin[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.stockItem', 'i')->addSelect('i')
            ->leftJoin('b.licencie', 'l')->addSelect('l')
            ->leftJoin('b.dirigeant', 'd')->addSelect('d')
            ->where('b.season = :season')
            ->setParameter('season', $season)
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DotationBesoin[] Besoins « à donner » de la saison, article + fournisseur préchargés. */
    public function findADonnerBySeason(Season $season): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.stockItem', 'i')->addSelect('i')
            ->leftJoin('i.fournisseur', 'f')->addSelect('f')
            ->where('b.season = :season')
            ->andWhere('b.statut = :statut')
            ->setParameter('season', $season)
            ->setParameter('statut', \App\Enum\DotationBesoinStatut::A_DONNER)
            ->getQuery()
            ->getResult();
    }

    /** @return DotationBesoin[] */
    public function findForLicencie(Licencie $licencie): array
    {
        return $this->findBy(['licencie' => $licencie]);
    }

    /** @return DotationBesoin[] */
    public function findForDirigeant(Dirigeant $dirigeant): array
    {
        return $this->findBy(['dirigeant' => $dirigeant]);
    }
}

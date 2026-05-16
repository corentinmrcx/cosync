<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Licencie;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Licencie>
 */
class LicencieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Licencie::class);
    }

    public function findByNumLicence(string $numLicence): ?Licencie
    {
        return $this->findOneBy(['numLicence' => $numLicence]);
    }

    public function findByUuid(Uuid $uuid): ?Licencie
    {
        return $this->find($uuid);
    }

    /** @return Licencie[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.season = :season')
            ->setParameter('season', $season)
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

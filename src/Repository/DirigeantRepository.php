<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Dirigeant> */
class DirigeantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dirigeant::class);
    }

    public function findByNumLicence(string $numLicence): ?Dirigeant
    {
        return $this->findOneBy(['numLicence' => $numLicence]);
    }

    public function findByNomPrenomSaison(string $nom, string $prenom, Season $season): ?Dirigeant
    {
        return $this->createQueryBuilder('d')
            ->where('d.nom = :nom')
            ->andWhere('d.prenom = :prenom')
            ->andWhere('d.season = :season')
            ->setParameter('nom', $nom)
            ->setParameter('prenom', $prenom)
            ->setParameter('season', $season)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUuid(Uuid $uuid): ?Dirigeant
    {
        return $this->find($uuid);
    }

    /** @return Dirigeant[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySeason(Season $season): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.uuid)')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Dirigeant[] */
    public function findBySeasonWithFilters(
        Season $season,
        ?string $search,
        ?int $teamId,
        ?int $roleId,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.role', 'r')
            ->leftJoin('d.team', 't')
            ->addSelect('r', 't')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(d.nom) LIKE :search OR LOWER(d.prenom) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($teamId !== null) {
            $qb->andWhere('t.id = :teamId')
                ->setParameter('teamId', $teamId);
        }

        if ($roleId !== null) {
            $qb->andWhere('r.id = :roleId')
                ->setParameter('roleId', $roleId);
        }

        return $qb->getQuery()->getResult();
    }
}

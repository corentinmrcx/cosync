<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\LicenceStatus;
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

    /** Fallback pour les licenciés importés avant l'ajout du num_licence */
    public function findByNomPrenomNaissance(string $nom, string $prenom, \DateTimeImmutable $dateNaissance): ?Licencie
    {
        return $this->createQueryBuilder('l')
            ->where('l.nom = :nom')
            ->andWhere('l.prenom = :prenom')
            ->andWhere('l.dateNaissance = :dateNaissance')
            ->setParameter('nom', $nom)
            ->setParameter('prenom', $prenom)
            ->setParameter('dateNaissance', $dateNaissance)
            ->getQuery()
            ->getOneOrNullResult();
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

    /** @return Licencie[] */
    public function findWithFilters(
        Season $season,
        ?Team $team = null,
        ?Category $category = null,
        ?LicenceStatus $status = null,
        ?string $search = null,
        int $limit = 25,
        int $offset = 0,
    ): array {
        return $this->buildFilterQuery($season, $team, $category, $status, $search)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countWithFilters(
        Season $season,
        ?Team $team = null,
        ?Category $category = null,
        ?LicenceStatus $status = null,
        ?string $search = null,
    ): int {
        return (int) $this->buildFilterQuery($season, $team, $category, $status, $search)
            ->select('COUNT(l.uuid)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function buildFilterQuery(
        Season $season,
        ?Team $team,
        ?Category $category,
        ?LicenceStatus $status,
        ?string $search,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.dossierClub', 'd')
            ->leftJoin('l.team', 't')
            ->join('l.category', 'c')
            ->addSelect('d', 't', 'c')
            ->where('l.season = :season')
            ->setParameter('season', $season)
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC');

        if ($team !== null) {
            $qb->andWhere('l.team = :team')->setParameter('team', $team);
        }
        if ($category !== null) {
            $qb->andWhere('l.category = :category')->setParameter('category', $category);
        }
        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(CONCAT(l.nom, \' \', l.prenom)) LIKE :search OR LOWER(CONCAT(l.prenom, \' \', l.nom)) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower($search, 'UTF-8') . '%');
        }

        return $qb;
    }
}

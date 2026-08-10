<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DocumentCible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentSignable> */
class DocumentSignableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSignable::class);
    }

    /**
     * Tous les documents de la saison, actifs ou non, pour l'écran d'administration.
     *
     * @return DocumentSignable[]
     */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.dirigeants', 'cible')
            ->addSelect('cible')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->orderBy('d.cible', 'ASC')
            ->addOrderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents actifs demandés à une population, dans l'ordre des étapes du formulaire.
     *
     * @return DocumentSignable[]
     */
    public function findActifsByCible(Season $season, DocumentCible $cible): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.dirigeants', 'designe')
            ->addSelect('designe')
            ->where('d.season = :season')
            ->andWhere('d.cible = :cible')
            ->andWhere('d.actif = true')
            ->setParameter('season', $season)
            ->setParameter('cible', $cible)
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsByCode(Season $season, string $code, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.season = :season')
            ->andWhere('d.code = :code')
            ->setParameter('season', $season)
            ->setParameter('code', $code);

        if ($exceptId !== null) {
            $qb->andWhere('d.id != :exceptId')->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneByCode(Season $season, string $code): ?DocumentSignable
    {
        return $this->findOneBy(['season' => $season, 'code' => $code]);
    }

    /** Prochaine position libre dans l'ordre des étapes, pour un nouveau document. */
    public function nextSortOrder(Season $season, DocumentCible $cible): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.sortOrder)')
            ->where('d.season = :season')
            ->andWhere('d.cible = :cible')
            ->setParameter('season', $season)
            ->setParameter('cible', $cible)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }
}

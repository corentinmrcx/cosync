<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Season>
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    public function findMostRecent(): ?Season
    {
        return $this->findOneBy([], ['createdAt' => 'DESC']);
    }

    public function existsByLabel(string $label): bool
    {
        return $this->count(['label' => $label]) > 0;
    }

    /**
     * Existe-t-il au moins une saison antérieure ? Le label (« 2025-2026 ») est unique et
     * son ordre lexicographique est chronologique, contrairement à createdAt qui reflète
     * l'ordre de saisie.
     */
    public function hasEarlierThan(Season $season): bool
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.label < :label')
            ->setParameter('label', $season->getLabel())
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return Season[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

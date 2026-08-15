<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Taille;
use App\Enum\TailleType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Taille>
 */
class TailleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Taille::class);
    }

    /** @return Taille[] Tout le référentiel, dans l'ordre d'affichage réglé par l'admin. */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByLibelle(TailleType $type, string $libelle): ?Taille
    {
        return $this->findOneBy(['type' => $type, 'libelle' => $libelle]);
    }

    public function dernierePosition(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COALESCE(MAX(t.position), -1)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

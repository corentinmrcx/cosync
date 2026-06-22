<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DirigeantRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DirigeantRole>
 */
class DirigeantRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DirigeantRole::class);
    }

    /** @return DirigeantRole[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.sortOrder', 'ASC')
            ->addOrderBy('r.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByLabel(string $label): ?DirigeantRole
    {
        return $this->findOneBy(['label' => $label]);
    }
}

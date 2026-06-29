<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockItem>
 */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /** @return StockItem[] Catalogue actif (actif = true), trié par catégorie puis nom. */
    public function findAllOrdered(bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.category', 'c')
            ->addSelect('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('i.nom', 'ASC');

        if (!$includeArchived) {
            $qb->andWhere('i.actif = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return string[] */
    public function findDistinctMarques(): array
    {
        return $this->findDistinctValues('marque');
    }

    /** @return string[] */
    public function findDistinctTaillesByKind(StockItemKind $kind): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.taille')
            ->distinct()
            ->where('i.taille IS NOT NULL')
            ->andWhere('i.kind = :kind')
            ->andWhere('i.actif = true')
            ->setParameter('kind', $kind)
            ->orderBy('i.taille', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'taille');
    }

    /** @return string[] */
    public function findDistinctCouleurs(): array
    {
        return $this->findDistinctValues('couleur');
    }

    /** @return string[] */
    private function findDistinctValues(string $field): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select("i.{$field}")
            ->distinct()
            ->where("i.{$field} IS NOT NULL")
            ->andWhere('i.actif = true')
            ->orderBy("i.{$field}", 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, $field);
    }
}

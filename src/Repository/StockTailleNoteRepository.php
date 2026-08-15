<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockItem;
use App\Entity\StockTailleNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockTailleNote>
 */
class StockTailleNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockTailleNote::class);
    }

    /**
     * Notes d'un article indexées par taille, pour l'affichage de sa ventilation.
     *
     * @return array<string, string>
     */
    public function parTaille(StockItem $item): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.taille AS taille', 'n.note AS note')
            ->where('n.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['taille']] = (string) $row['note'];
        }

        return $out;
    }

    public function findOneForTaille(StockItem $item, string $taille): ?StockTailleNote
    {
        return $this->findOneBy(['item' => $item, 'taille' => $taille]);
    }

    /** @return StockTailleNote[] */
    public function findByItem(StockItem $item): array
    {
        return $this->findBy(['item' => $item]);
    }

    /** Notes portées sur cette taille — garde du référentiel. */
    public function countByTaille(string $libelle): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.taille = :libelle')
            ->setParameter('libelle', $libelle)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> Tailles portant une note. */
    public function findDistinctTailles(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('DISTINCT n.taille AS taille')
            ->getQuery()
            ->getScalarResult();

        return array_map('strval', array_column($rows, 'taille'));
    }
}

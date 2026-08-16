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

    /**
     * Articles écoulés à la place de celui-ci, dans l'ordre où ils doivent partir : les
     * archivés d'abord — un article sorti du catalogue est justement celui dont il faut se
     * débarrasser — puis par nom, pour que l'arbitrage soit reproductible d'un écran à l'autre.
     *
     * @return list<StockItem>
     */
    public function findSubstituts(StockItem $officiel): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.remplaceArticle = :officiel')
            ->setParameter('officiel', $officiel)
            ->orderBy('i.actif', 'ASC')
            ->addOrderBy('i.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countSubstituts(StockItem $officiel): int
    {
        return $this->count(['remplaceArticle' => $officiel]);
    }

    /**
     * Articles que celui-ci peut déclarer remplacer. On écarte l'article lui-même, les
     * articles d'écoulement (pas de chaîne : Nike → Adidas → ERIMA) et, si l'article est
     * déjà remplacé par d'autres, tous les candidats — il est une cible, il ne peut pas
     * devenir substitut à son tour.
     *
     * @return list<StockItem>
     */
    public function findCiblesEcoulementPossibles(?StockItem $item): array
    {
        if ($item !== null && $this->countSubstituts($item) > 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('i')
            ->where('i.actif = true')
            ->andWhere('i.kind = :equipement')
            ->andWhere('i.remplaceArticle IS NULL')
            ->setParameter('equipement', StockItemKind::EQUIPEMENT)
            ->orderBy('i.nom', 'ASC');

        if ($item !== null) {
            $qb->andWhere('i.id != :self')->setParameter('self', $item->getId());
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

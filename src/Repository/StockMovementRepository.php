<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockMovement>
 */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /** @return StockMovement[] */
    public function findByItem(StockItem $item): array
    {
        return $this->findBy(['item' => $item], ['createdAt' => 'DESC']);
    }

    public function getCurrentStock(StockItem $item): int
    {
        $entrees = (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.quantite), 0)')
            ->where('m.item = :item')
            ->andWhere('m.type = :type')
            ->setParameter('item', $item)
            ->setParameter('type', StockMovementType::ENTREE)
            ->getQuery()
            ->getSingleScalarResult();

        $sorties = (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.quantite), 0)')
            ->where('m.item = :item')
            ->andWhere('m.type IN (:types)')
            ->setParameter('item', $item)
            ->setParameter('types', [StockMovementType::SORTIE, StockMovementType::REBUT])
            ->getQuery()
            ->getSingleScalarResult();

        return $entrees - $sorties;
    }

    /**
     * Stock net par taille pour un article : { taille|'' => quantité }.
     * Clé '' = mouvements sans taille (épicerie / objet sans taille).
     *
     * @return array<string, int>
     */
    public function getStockGroupedByTaille(StockItem $item): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.taille AS taille')
            ->addSelect('SUM(CASE WHEN m.type = :entree THEN m.quantite ELSE -m.quantite END) AS net')
            ->where('m.item = :item')
            ->setParameter('item', $item)
            ->setParameter('entree', StockMovementType::ENTREE)
            ->groupBy('m.taille')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['taille'] ?? ''] = (int) $row['net'];
        }

        return $out;
    }

    public function getCurrentStockByTaille(StockItem $item, ?string $taille): int
    {
        return $this->getStockGroupedByTaille($item)[$taille ?? ''] ?? 0;
    }

    public function findBySumupTransactionId(string $txId): ?StockMovement
    {
        return $this->findOneBy(['sumupTransactionId' => $txId]);
    }

    public function hasDotation(StockItem $item, Licencie $licencie): bool
    {
        return $this->count([
            'item' => $item,
            'licencie' => $licencie,
            'source' => StockMovementSource::DOTATION,
        ]) > 0;
    }

    /** @return StockMovement[] */
    public function findDotationsByDirigeant(Dirigeant $dirigeant): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.item', 'i')
            ->where('m.dirigeant = :dirigeant')
            ->andWhere('m.source = :source')
            ->setParameter('dirigeant', $dirigeant)
            ->setParameter('source', StockMovementSource::DOTATION)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return StockMovement[] */
    public function findDotationsByLicencie(Licencie $licencie): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.item', 'i')
            ->where('m.licencie = :licencie')
            ->andWhere('m.source = :source')
            ->setParameter('licencie', $licencie)
            ->setParameter('source', StockMovementSource::DOTATION)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{search?: string, item_id?: string, type?: string, source?: string, date_from?: string, date_to?: string} $filters
     * @return array{movements: StockMovement[], total: int}
     */
    public function findWithFilters(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.item', 'i')
            ->orderBy('m.createdAt', 'DESC');

        if (!empty($filters['search'])) {
            // Le nom de l'article et la note du mouvement : les deux seuls textes libres
            // sur lesquels l'admin cherche (« où est passée la commande de chasubles ? »).
            $qb->andWhere('LOWER(i.nom) LIKE :recherche OR LOWER(m.note) LIKE :recherche')
               ->setParameter('recherche', '%' . mb_strtolower(trim((string) $filters['search'])) . '%');
        }
        if (!empty($filters['item_id'])) {
            $qb->andWhere('i.id = :itemId')->setParameter('itemId', $filters['item_id']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('m.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['source'])) {
            $qb->andWhere('m.source = :source')->setParameter('source', $filters['source']);
        }
        if (!empty($filters['date_from'])) {
            $qb->andWhere('m.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTimeImmutable($filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $qb->andWhere('m.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTimeImmutable($filters['date_to'] . ' 23:59:59'));
        }

        $total = (int) (clone $qb)
            ->select('COUNT(m.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $movements = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['movements' => $movements, 'total' => $total];
    }
}

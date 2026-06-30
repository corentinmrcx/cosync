<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommandeLigne;
use App\Enum\CommandeStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommandeLigne>
 */
class CommandeLigneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommandeLigne::class);
    }

    /**
     * Quantités commandées non encore reçues, par (article, taille).
     * Clé = "{itemId}|{taille|''}".
     *
     * @return array<string, int>
     */
    public function sumPendingByItemTaille(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.stockItem) AS item_id')
            ->addSelect('l.taille AS taille')
            ->addSelect('SUM(l.quantite - l.quantiteRecue) AS pending')
            ->join('l.commande', 'c')
            ->where('c.statut IN (:statuts)')
            ->setParameter('statuts', [CommandeStatut::COMMANDEE, CommandeStatut::RECUE_PARTIELLE])
            ->groupBy('l.stockItem')
            ->addGroupBy('l.taille')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $pending = (int) $row['pending'];
            if ($pending > 0) {
                $out[$row['item_id'] . '|' . ($row['taille'] ?? '')] = $pending;
            }
        }

        return $out;
    }
}

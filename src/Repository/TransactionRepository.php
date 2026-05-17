<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /** @return Transaction[] */
    public function findByLicencie(Licencie $licencie): array
    {
        return $this->findBy(['licencie' => $licencie], ['datePaiement' => 'DESC']);
    }

    public function findByLicencieAndSeason(Licencie $licencie, Season $season): ?Transaction
    {
        return $this->findOneBy(['licencie' => $licencie, 'season' => $season]);
    }
}

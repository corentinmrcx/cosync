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

    /** @return Transaction[] */
    public function findAllByLicencieAndSeason(Licencie $licencie, Season $season): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.licencie = :licencie')
            ->andWhere('t.season = :season')
            ->setParameter('licencie', $licencie)
            ->setParameter('season', $season)
            ->orderBy('t.datePaiement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Paiement déjà encaissé chez le prestataire en ligne — garde-fou d'idempotence. */
    public function findOneByExternalPaymentId(string $externalPaymentId): ?Transaction
    {
        return $this->findOneBy(['externalPaymentId' => $externalPaymentId]);
    }

    public function sumByLicencieAndSeason(Licencie $licencie, Season $season): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.montant)')
            ->where('t.licencie = :licencie')
            ->andWhere('t.season = :season')
            ->setParameter('licencie', $licencie)
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }
}

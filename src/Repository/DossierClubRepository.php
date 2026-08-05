<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DossierClub;
use App\Entity\Licencie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierClub>
 */
class DossierClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierClub::class);
    }

    public function findByLicencie(Licencie $licencie): ?DossierClub
    {
        return $this->findOneBy(['licencie' => $licencie]);
    }

    /**
     * Dossiers ayant lancé un paiement HelloAsso sans qu'aucun encaissement en ligne
     * ne soit encore enregistré pour le licencié.
     *
     * @return DossierClub[]
     */
    public function findWithPendingHelloAssoPayment(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.helloassoCheckoutIntentId IS NOT NULL')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\Transaction t
                WHERE t.licencie = d.licencie AND t.externalPaymentId IS NOT NULL
            )')
            ->getQuery()
            ->getResult();
    }

    /** @return DossierClub[] */
    public function findWithLocalPdf(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.isSigned = true')
            ->andWhere('d.signaturePath LIKE :prefix')
            ->setParameter('prefix', '/var/www/html/var/pdfs/%')
            ->getQuery()
            ->getResult();
    }
}

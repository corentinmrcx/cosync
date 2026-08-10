<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
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

    /** Au-delà, une intention non aboutie est considérée comme abandonnée et n'est plus interrogée. */
    private const RECONCILIATION_WINDOW = '-90 days';

    /**
     * Dossiers ayant lancé un paiement HelloAsso dont l'encaissement n'a pas encore
     * abouti à une licence validée.
     *
     * Le critère est le statut du dossier, pas l'existence d'une transaction en ligne :
     * un licencié qui relance un paiement après un premier encaissement partiel doit
     * rester réconcilié, alors qu'il porte déjà une transaction HelloAsso.
     *
     * @return DossierClub[]
     */
    public function findWithPendingHelloAssoPayment(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.helloassoCheckoutIntentId IS NOT NULL')
            ->andWhere('d.status != :validated')
            ->andWhere('d.helloassoCheckoutStartedAt IS NULL OR d.helloassoCheckoutStartedAt >= :limite')
            ->setParameter('validated', LicenceStatus::VALIDATED)
            ->setParameter('limite', new \DateTimeImmutable(self::RECONCILIATION_WINDOW))
            ->getQuery()
            ->getResult();
    }
}

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

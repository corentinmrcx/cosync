<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Detenteur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Detenteur> */
class DetenteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Detenteur::class);
    }

    /** @return Detenteur[] tous les détenteurs connus du club, triés par nom */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByNumLicence(string $numLicence): ?Detenteur
    {
        return $this->findOneBy(['numLicence' => $numLicence]);
    }
}

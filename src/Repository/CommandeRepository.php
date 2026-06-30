<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\Season;
use App\Enum\CommandeStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /** @return Commande[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')->addSelect('f')
            ->where('c.season = :season')
            ->setParameter('season', $season)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Commande[] */
    public function findBrouillonsBySeason(Season $season): array
    {
        return $this->findBy([
            'season' => $season,
            'statut' => CommandeStatut::BROUILLON,
        ]);
    }
}

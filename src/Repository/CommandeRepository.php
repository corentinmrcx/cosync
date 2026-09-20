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

    /**
     * Les commandes de la saison, la dernière en tête.
     *
     * Les bons d'une même génération naissent dans la même seconde : sans second critère,
     * ils sortaient dans un ordre qui changeait d'un affichage à l'autre. Le fournisseur
     * les range, comme partout ailleurs dans l'approvisionnement.
     *
     * @return Commande[]
     */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')->addSelect('f')
            ->where('c.season = :season')
            ->setParameter('season', $season)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('f.nom', 'ASC')
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

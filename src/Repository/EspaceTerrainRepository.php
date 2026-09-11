<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\EspaceTerrain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EspaceTerrain> */
class EspaceTerrainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EspaceTerrain::class);
    }

    /**
     * Le référentiel entier, terrains retirés compris — c'est la vue d'administration.
     *
     * @return EspaceTerrain[]
     */
    public function findOrdonnes(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.ordre', 'ASC')
            ->addOrderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ce que le sélecteur d'un créneau propose : un terrain retiré du service ne doit plus
     * entrer dans une nouvelle réservation, mais reste nommé par les créneaux existants.
     *
     * @return EspaceTerrain[]
     */
    public function findActifs(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actif = true')
            ->orderBy('e.ordre', 'ASC')
            ->addOrderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findParNom(string $nom): ?EspaceTerrain
    {
        return $this->findOneBy(['nom' => $nom]);
    }

    /** Le rang suivant : un terrain ajouté se pose en fin de liste, jamais au milieu. */
    public function prochainOrdre(): int
    {
        return 1 + (int) $this->createQueryBuilder('e')
            ->select('COALESCE(MAX(e.ordre), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

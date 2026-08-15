<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\GrilleTailleValeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GrilleTailleValeur>
 */
class GrilleTailleValeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrilleTailleValeur::class);
    }

    /**
     * Nombre de lignes de grille qui désignent cette taille, comme libellé fournisseur ou
     * comme taille couverte. Une taille employée par une grille ne doit pas plus disparaître
     * qu'une taille employée par un mouvement : la traduction en dépend.
     */
    public function countByTaille(string $libelle): int
    {
        return $this->compter('v.cible', $libelle) + $this->compter('v.couvertures', $libelle);
    }

    /** @return list<string> */
    public function findDistinctTailles(): array
    {
        $libelles = [];
        foreach ([...$this->libellesDe('v.cible'), ...$this->libellesDe('v.couvertures')] as $libelle) {
            $libelles[$libelle] = true;
        }

        return array_keys($libelles);
    }

    private function compter(string $relation, string $libelle): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->join($relation, 't')
            ->where('t.libelle = :libelle')
            ->setParameter('libelle', $libelle)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> */
    private function libellesDe(string $relation): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('DISTINCT t.libelle AS libelle')
            ->join($relation, 't')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'libelle');
    }
}

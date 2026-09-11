<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\CreneauOccupation;
use App\Entity\EspaceTerrain;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CreneauOccupation> */
class CreneauOccupationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreneauOccupation::class);
    }

    /**
     * Toute la grille d'une saison, triée jour puis heure — l'ordre de lecture d'une colonne.
     *
     * Le tri se fait en PHP : `jour` est stocké en chaîne (`'mercredi'`), qu'un `ORDER BY`
     * SQL rangerait par ordre alphabétique — dimanche en tête, mercredi avant lundi. C'est
     * `JourSemaine::numero()` qui porte l'ordre de la semaine, et lui seul.
     *
     * L'équipe et le terrain sont joints d'office : la grille les affiche tous, et les
     * laisser en chargement paresseux sortait une requête par bloc.
     *
     * @return list<CreneauOccupation>
     */
    public function findParSaison(Season $season): array
    {
        /** @var list<CreneauOccupation> $creneaux */
        $creneaux = $this->createQueryBuilder('c')
            ->addSelect('e', 't')
            ->leftJoin('c.equipe', 'e')
            ->innerJoin('c.espace', 't')
            ->where('c.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();

        usort($creneaux, static fn (CreneauOccupation $a, CreneauOccupation $b) => $a->cleDeTri() <=> $b->cleDeTri());

        return $creneaux;
    }

    public function compterParSaison(Season $season): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de créneaux par terrain, toutes saisons confondues — l'écran du référentiel
     * s'en sert pour dire, avant le clic, lequel ne se supprime pas.
     *
     * @return array<int, int> id du terrain => nombre de créneaux
     */
    public function compterParTerrain(): array
    {
        /** @var list<array{espace: int, total: int}> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.espace) AS espace', 'COUNT(c.id) AS total')
            ->groupBy('c.espace')
            ->getQuery()
            ->getResult();

        $comptes = [];

        foreach ($lignes as $ligne) {
            $comptes[(int) $ligne['espace']] = (int) $ligne['total'];
        }

        return $comptes;
    }

    /** Un terrain encore réservé quelque part ne se supprime pas : ce compteur le dit. */
    public function compterParEspace(EspaceTerrain $espace): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.espace = :espace')
            ->setParameter('espace', $espace)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchDomicile;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MatchDomicile> */
class MatchDomicileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchDomicile::class);
    }

    /**
     * Tous les matchs de la saison, masqués compris — c'est la vue d'administration.
     *
     * @return MatchDomicile[]
     */
    public function findParSaison(Season $season): array
    {
        return $this->trierParDateEtHeure(
            $this->createQueryBuilder('m')
                ->where('m.season = :season')
                ->setParameter('season', $season)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * Ce qui part à l'impression : la période demandée, masqués exclus.
     *
     * Les bornes sont inclusives des deux côtés — un planning « du 1er au 30 septembre »
     * doit contenir le match du 30.
     *
     * @return MatchDomicile[]
     */
    public function findPourDocument(Season $season, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        return $this->trierParDateEtHeure(
            $this->createQueryBuilder('m')
                ->where('m.season = :season')
                ->andWhere('m.masque = false')
                ->andWhere('m.date >= :du')
                ->andWhere('m.date <= :au')
                ->setParameter('season', $season)
                ->setParameter('du', $du->setTime(0, 0))
                ->setParameter('au', $au->setTime(0, 0))
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * Les matchs fédéraux de la saison, indexés par `ma_no` — la table de correspondance
     * dont la synchronisation a besoin pour décider entre créer et mettre à jour.
     *
     * Les matchs détachés y figurent : ils gardent leur `fffMaNo` précisément pour que la
     * sync les reconnaisse et ne les recrée pas.
     *
     * @return array<int, MatchDomicile>
     */
    public function findParMaNo(Season $season): array
    {
        /** @var MatchDomicile[] $matchs */
        $matchs = $this->createQueryBuilder('m')
            ->where('m.season = :season')
            ->andWhere('m.fffMaNo IS NOT NULL')
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();

        $parMaNo = [];

        foreach ($matchs as $match) {
            $parMaNo[(int) $match->getFffMaNo()] = $match;
        }

        return $parMaNo;
    }

    public function compterParSaison(Season $season): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Le tri se fait en PHP : l'heure est une chaîne nullable, et un `ORDER BY` SQL
     * placerait les horaires non fixés en tête selon le moteur. `cleDeTri()` les renvoie
     * en fin de journée, ce qui est le seul ordre lisible sur un planning imprimé.
     *
     * @param list<MatchDomicile> $matchs
     *
     * @return list<MatchDomicile>
     */
    private function trierParDateEtHeure(array $matchs): array
    {
        usort($matchs, static fn (MatchDomicile $a, MatchDomicile $b) => $a->cleDeTri() <=> $b->cleDeTri());

        return $matchs;
    }
}

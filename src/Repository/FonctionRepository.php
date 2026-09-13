<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fonction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fonction>
 */
class FonctionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fonction::class);
    }

    /** @return Fonction[] Tout le référentiel, dans l'ordre d'affichage réglé par l'admin. */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByLibelle(string $libelle): ?Fonction
    {
        return $this->findOneBy(['libelle' => $libelle]);
    }

    public function dernierePosition(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COALESCE(MAX(f.position), -1)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de dirigeants portant chaque fonction, toutes saisons confondues — c'est ce
     * qui interdit la suppression. Une seule requête groupée : l'écran n'affiche qu'un
     * compteur par ligne, un comptage par fonction en aurait demandé autant que de lignes.
     *
     * @return array<int, int> nombre de dirigeants, indexé par id de fonction ; absent si zéro
     */
    public function utilisations(): array
    {
        /** @var list<array{id: int, total: int}> $lignes */
        $lignes = $this->getEntityManager()
            ->createQuery('SELECT f.id AS id, COUNT(d.uuid) AS total FROM App\Entity\Dirigeant d JOIN d.fonctions f GROUP BY f.id')
            ->getArrayResult();

        $utilisations = [];
        foreach ($lignes as $ligne) {
            $utilisations[(int) $ligne['id']] = (int) $ligne['total'];
        }

        return $utilisations;
    }

    public function compterDirigeants(Fonction $fonction): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(d.uuid) FROM App\Entity\Dirigeant d JOIN d.fonctions f WHERE f = :fonction')
            ->setParameter('fonction', $fonction)
            ->getSingleScalarResult();
    }
}

<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoleAcces;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoleAcces>
 */
class RoleAccesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleAcces::class);
    }

    /** @return RoleAcces[] */
    public function findAllOrderedByNom(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNom(string $nom): ?RoleAcces
    {
        return $this->findOneBy(['nom' => $nom]);
    }

    /**
     * Nombre de comptes rattachés à chaque rôle, indexé par id de rôle.
     *
     * Groupé plutôt que compté rôle par rôle : la liste des rôles affiche ce nombre sur
     * chaque ligne, et une requête par ligne se verrait dès le premier écran.
     *
     * Un rôle que personne ne porte est **absent** du tableau — l'appelant lit `?? 0`.
     *
     * @return array<int, int>
     */
    public function compterUtilisateursParRole(): array
    {
        /** @var list<array{id: int, total: int}> $lignes */
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('r.id AS id', 'COUNT(u.id) AS total')
            ->from(User::class, 'u')
            ->innerJoin('u.rolesAcces', 'r')
            ->groupBy('r.id')
            ->getQuery()
            ->getArrayResult();

        $comptes = [];

        foreach ($lignes as $ligne) {
            $comptes[$ligne['id']] = (int) $ligne['total'];
        }

        return $comptes;
    }
}

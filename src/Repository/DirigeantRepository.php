<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Dirigeant> */
class DirigeantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dirigeant::class);
    }

    public function findByNumLicence(string $numLicence, Season $season): ?Dirigeant
    {
        return $this->findOneBy(['numLicence' => $numLicence, 'season' => $season]);
    }

    public function findByNomPrenomSaison(string $nom, string $prenom, Season $season): ?Dirigeant
    {
        return $this->createQueryBuilder('d')
            ->where('d.nom = :nom')
            ->andWhere('d.prenom = :prenom')
            ->andWhere('d.season = :season')
            ->setParameter('nom', $nom)
            ->setParameter('prenom', $prenom)
            ->setParameter('season', $season)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUuid(Uuid $uuid): ?Dirigeant
    {
        return $this->find($uuid);
    }

    /** @return Dirigeant[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySeason(Season $season): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.uuid)')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Dirigeants de la saison qui n'ont pas encore complété leur formulaire. */
    public function countFormulairesEnAttente(Season $season): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.uuid)')
            ->where('d.season = :season')
            ->andWhere('d.formCompletedAt IS NULL')
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dirigeants qui n'ont jamais reçu le lien de leur formulaire.
     *
     * C'est `linkSentAt` qui fait foi, pas le jeton : celui-ci est effacé dès le dossier
     * complet, il dirait « jamais contacté » de quelqu'un qui a tout signé.
     *
     * @return Dirigeant[]
     */
    public function findLienJamaisEnvoye(Season $season): array
    {
        return $this->queryLienJamaisEnvoye($season)
            ->leftJoin('d.team', 't')
            ->addSelect('t')
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countLienJamaisEnvoye(Season $season): int
    {
        return (int) $this->queryLienJamaisEnvoye($season)
            ->select('COUNT(d.uuid)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function queryLienJamaisEnvoye(Season $season): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->where('d.season = :season')
            ->andWhere('d.linkSentAt IS NULL')
            // Les licences administratives ne remplissent aucun formulaire : les proposer
            // saison après saison ferait d'un état normal un retard permanent.
            ->andWhere('d.licenceAdministrative = false')
            ->setParameter('season', $season);
    }

    /** @return Dirigeant[] */
    public function findBySeasonWithFilters(
        Season $season,
        ?string $search,
        ?int $teamId,
        ?DirigeantRole $role,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.team', 't')
            ->addSelect('t')
            ->where('d.season = :season')
            ->setParameter('season', $season)
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(d.nom) LIKE :search OR LOWER(d.prenom) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($teamId !== null) {
            $qb->andWhere('t.id = :teamId')
                ->setParameter('teamId', $teamId);
        }

        if ($role !== null) {
            $qb->andWhere('d.role = :role')
                ->setParameter('role', $role);
        }

        return $qb->getQuery()->getResult();
    }

    /** Dirigeants qui déclarent cette taille — garde du référentiel. */
    public function countByTaille(string $libelle): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.uuid)')
            ->where('d.tailleHaut = :libelle OR d.tailleBas = :libelle OR d.pointure = :libelle')
            ->setParameter('libelle', $libelle)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> Tailles réellement déclarées par des dirigeants. */
    public function findDistinctTailles(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.tailleHaut AS haut', 'd.tailleBas AS bas', 'd.pointure AS pointure')
            ->getQuery()
            ->getScalarResult();

        $libelles = [];
        foreach ($rows as $row) {
            foreach ($row as $valeur) {
                if (is_string($valeur) && $valeur !== '') {
                    $libelles[$valeur] = true;
                }
            }
        }

        return array_keys($libelles);
    }
}

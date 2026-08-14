<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Licencie>
 */
class LicencieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Licencie::class);
    }

    public function findByNumLicence(string $numLicence, Season $season): ?Licencie
    {
        return $this->findOneBy(['numLicence' => $numLicence, 'season' => $season]);
    }

    /**
     * Licenciés dont le paiement est confirmé — éligibles aux dotations.
     *
     * @return Licencie[]
     */
    public function findValidatedBySeason(Season $season): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.dossierClub', 'd')
            ->where('l.season = :season')
            ->andWhere('d.status = :status')
            ->setParameter('season', $season)
            ->setParameter('status', LicenceStatus::VALIDATED)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ce numéro de licence apparaît-il dans une saison antérieure ? Sert de contrôle croisé
     * à la colonne « Nature » de l'export FootClubs. Les saisons sont ordonnées par leur
     * label (« 2025-2026 »), qui est chronologique.
     */
    public function existsInEarlierSeason(string $numLicence, Season $season): bool
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.uuid)')
            ->join('l.season', 's')
            ->where('l.numLicence = :numLicence')
            ->andWhere('s.label < :label')
            ->setParameter('numLicence', $numLicence)
            ->setParameter('label', $season->getLabel())
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Fallback pour les licenciés importés avant l'ajout du num_licence */
    public function findByNomPrenomNaissance(string $nom, string $prenom, \DateTimeImmutable $dateNaissance, Season $season): ?Licencie
    {
        return $this->createQueryBuilder('l')
            ->where('l.nom = :nom')
            ->andWhere('l.prenom = :prenom')
            ->andWhere('l.dateNaissance = :dateNaissance')
            ->andWhere('l.season = :season')
            ->setParameter('nom', $nom)
            ->setParameter('prenom', $prenom)
            ->setParameter('dateNaissance', $dateNaissance)
            ->setParameter('season', $season)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUuid(Uuid $uuid): ?Licencie
    {
        return $this->find($uuid);
    }

    public function countByCategory(Category $category): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.uuid)')
            ->where('l.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Licencie[] */
    public function findBySeason(Season $season): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.season = :season')
            ->setParameter('season', $season)
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Licenciés qui n'ont jamais reçu leur lien d'inscription.
     *
     * C'est `linkSentAt` qui fait foi, pas le statut du dossier : le statut peut avoir
     * avancé par une saisie admin, l'envoi du mail est un fait daté.
     *
     * @return Licencie[]
     */
    public function findLienJamaisEnvoye(Season $season): array
    {
        return $this->queryLienJamaisEnvoye($season)
            ->leftJoin('l.team', 't')
            ->addSelect('t')
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countLienJamaisEnvoye(Season $season): int
    {
        return (int) $this->queryLienJamaisEnvoye($season)
            ->select('COUNT(l.uuid)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function queryLienJamaisEnvoye(Season $season): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->where('l.season = :season')
            ->andWhere('l.linkSentAt IS NULL')
            ->setParameter('season', $season);
    }

    /**
     * Licenciés à qui la boutique reste à annoncer : dossier complété, annonce jamais partie.
     *
     * Le dossier complété est la borne volontaire — la boutique est facultative, et un mail
     * de plus à qui n'a pas encore rempli son inscription la ferait passer au second plan.
     *
     * @return Licencie[]
     */
    public function findBoutiqueAAnnoncer(Season $season): array
    {
        return $this->queryBoutiqueAAnnoncer($season)
            ->leftJoin('l.team', 't')
            ->addSelect('t')
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBoutiqueAAnnoncer(Season $season): int
    {
        return (int) $this->queryBoutiqueAAnnoncer($season)
            ->select('COUNT(l.uuid)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function queryBoutiqueAAnnoncer(Season $season): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->join('l.dossierClub', 'd')
            ->where('l.season = :season')
            ->andWhere('d.formCompletedAt IS NOT NULL')
            ->andWhere('l.boutiqueAnnonceeAt IS NULL')
            ->andWhere('l.email IS NOT NULL')
            ->setParameter('season', $season);
    }

    /** @return Licencie[] */
    public function findSansEquipe(Season $season): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.category', 'c')
            ->addSelect('c')
            ->where('l.season = :season')
            ->andWhere('l.team IS NULL')
            ->setParameter('season', $season)
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Licencie[] */
    public function findWithFilters(
        Season $season,
        ?Team $team = null,
        ?Category $category = null,
        ?LicenceStatus $status = null,
        ?string $search = null,
        ?NatureLicence $nature = null,
        int $limit = 25,
        int $offset = 0,
    ): array {
        return $this->buildFilterQuery($season, $team, $category, $status, $search, $nature)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countWithFilters(
        Season $season,
        ?Team $team = null,
        ?Category $category = null,
        ?LicenceStatus $status = null,
        ?string $search = null,
        ?NatureLicence $nature = null,
    ): int {
        return (int) $this->buildFilterQuery($season, $team, $category, $status, $search, $nature)
            ->select('COUNT(l.uuid)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function buildFilterQuery(
        Season $season,
        ?Team $team,
        ?Category $category,
        ?LicenceStatus $status,
        ?string $search,
        ?NatureLicence $nature = null,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.dossierClub', 'd')
            ->leftJoin('l.team', 't')
            ->join('l.category', 'c')
            ->addSelect('d', 't', 'c')
            ->where('l.season = :season')
            ->setParameter('season', $season)
            ->orderBy('l.nom', 'ASC')
            ->addOrderBy('l.prenom', 'ASC');

        if ($team !== null) {
            $qb->andWhere('l.team = :team')->setParameter('team', $team);
        }
        if ($category !== null) {
            $qb->andWhere('l.category = :category')->setParameter('category', $category);
        }
        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }
        if ($nature !== null) {
            $qb->andWhere('l.natureLicence = :nature')->setParameter('nature', $nature);
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(CONCAT(l.nom, \' \', l.prenom)) LIKE :search OR LOWER(CONCAT(l.prenom, \' \', l.nom)) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower($search, 'UTF-8') . '%');
        }

        return $qb;
    }
}

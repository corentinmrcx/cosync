<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Enum\CleMouvementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CleMouvement>
 */
class CleMouvementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleMouvement::class);
    }

    /**
     * Tout l'historique du club, ordonné pour le pli séquentiel du service : par
     * personne, puis chronologiquement (id en tie-break, deux mouvements pouvant
     * partager la même date).
     *
     * Aucun filtre de saison : une clé remise en janvier est toujours dehors en
     * septembre, et c'est précisément ce que le registre doit savoir dire.
     *
     * @return CleMouvement[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.detenteur', 'd')
            ->addSelect('d')
            ->orderBy('d.nom', 'ASC')
            ->addOrderBy('d.prenom', 'ASC')
            ->addOrderBy('m.dateMouvement', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return CleMouvement[] historique complet d'une personne, du plus récent au plus ancien */
    public function findByDetenteur(Detenteur $detenteur): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.detenteur = :detenteur')
            ->setParameter('detenteur', $detenteur)
            ->orderBy('m.dateMouvement', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Nombre de clés actuellement détenues par une personne */
    public function getSolde(Detenteur $detenteur): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(CASE WHEN m.type = :remise THEN m.quantite ELSE -m.quantite END), 0)')
            ->where('m.detenteur = :detenteur')
            ->setParameter('detenteur', $detenteur)
            ->setParameter('remise', CleMouvementType::REMISE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Une page de l'historique du club, du plus récent au plus ancien.
     *
     * L'écran des mouvements montre **tout** l'historique, pas les derniers en date :
     * une restitution ou une perte n'apparaît nulle part ailleurs, la tronquer les
     * ferait disparaître dès le mouvement suivant.
     *
     * @return array{mouvements: CleMouvement[], total: int}
     */
    public function findPage(int $page, int $parPage): array
    {
        $total = (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $mouvements = $this->createQueryBuilder('m')
            ->join('m.detenteur', 'd')
            ->addSelect('d')
            ->orderBy('m.dateMouvement', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return ['mouvements' => $mouvements, 'total' => $total];
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByDetenteur(Detenteur $detenteur): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.detenteur = :detenteur')
            ->setParameter('detenteur', $detenteur)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

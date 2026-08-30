<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierClub>
 */
class DossierClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierClub::class);
    }

    public function findByLicencie(Licencie $licencie): ?DossierClub
    {
        return $this->findOneBy(['licencie' => $licencie]);
    }

    /** Au-delà, une intention non aboutie est considérée comme abandonnée et n'est plus interrogée. */
    private const RECONCILIATION_WINDOW = '-90 days';

    /**
     * Dossiers ayant lancé un paiement HelloAsso dont l'encaissement n'a pas encore
     * soldé la cotisation.
     *
     * Le critère est le statut du dossier, pas l'existence d'une transaction en ligne :
     * un licencié qui relance un paiement après un premier encaissement partiel doit
     * rester réconcilié, alors qu'il porte déjà une transaction HelloAsso.
     *
     * On sort de la réconciliation dès le solde (`A_VALIDER_FFF`), pas à la validation
     * FootClubs : celle-ci est un geste du club, elle ne dit rien de l'encaissement.
     *
     * @return DossierClub[]
     */
    public function findWithPendingHelloAssoPayment(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.helloassoCheckoutIntentId IS NOT NULL')
            ->andWhere('d.status NOT IN (:soldes)')
            ->andWhere('d.helloassoCheckoutStartedAt IS NULL OR d.helloassoCheckoutStartedAt >= :limite')
            ->setParameter('soldes', LicenceStatus::soldes())
            ->setParameter('limite', new \DateTimeImmutable(self::RECONCILIATION_WINDOW))
            ->getQuery()
            ->getResult();
    }

    /** Dossiers qui désignent cette taille, tous champs confondus — garde du référentiel. */
    public function countByTaille(string $libelle): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.tailleHaut = :libelle OR d.tailleBas = :libelle OR d.pointure = :libelle')
            ->setParameter('libelle', $libelle)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tailles réellement portées par des dossiers, tous champs confondus. Une requête pour
     * tout le référentiel plutôt qu'un comptage par taille.
     *
     * @return list<string>
     */
    public function findDistinctTailles(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.tailleHaut AS haut', 'd.tailleBas AS bas', 'd.pointure AS pointure')
            ->getQuery()
            ->getScalarResult();

        return self::libellesDe($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function libellesDe(array $rows): array
    {
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

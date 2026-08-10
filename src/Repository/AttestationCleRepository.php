<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttestationCle;
use App\Entity\Detenteur;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<AttestationCle> */
class AttestationCleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttestationCle::class);
    }

    public function findByUuid(Uuid $uuid): ?AttestationCle
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * La dernière attestation de chaque détenteur pour la saison, indexée par id de
     * détenteur. La table étant append-only, « la dernière » est celle qui décrit
     * l'état courant : les précédentes restent consultables à leur date.
     *
     * @return array<int, AttestationCle>
     */
    public function findDernieresParDetenteur(Season $season): array
    {
        /** @var AttestationCle[] $attestations */
        $attestations = $this->createQueryBuilder('a')
            ->join('a.detenteur', 'd')
            ->addSelect('d')
            ->where('a.season = :season')
            ->setParameter('season', $season)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        $parDetenteur = [];

        foreach ($attestations as $attestation) {
            $parDetenteur[$attestation->getDetenteur()->getId()] = $attestation;
        }

        return $parDetenteur;
    }

    public function findDerniereDe(Detenteur $detenteur, Season $season): ?AttestationCle
    {
        return $this->createQueryBuilder('a')
            ->where('a.detenteur = :detenteur')
            ->andWhere('a.season = :season')
            ->setParameter('detenteur', $detenteur)
            ->setParameter('season', $season)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return AttestationCle[] attestations signées d'une personne, toutes saisons, de la plus ancienne à la plus récente */
    public function findSigneesDe(Detenteur $detenteur): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.season', 's')
            ->addSelect('s')
            ->where('a.detenteur = :detenteur')
            ->andWhere('a.signedAt IS NOT NULL')
            ->setParameter('detenteur', $detenteur)
            ->orderBy('a.signedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Attestations signées dont le PDF n'a pas encore été uploadé sur Drive
     * (la colonne porte encore un chemin local absolu). Pour le rattrapage.
     *
     * @return AttestationCle[]
     */
    public function findWithLocalPdf(): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.detenteur', 'd')
            ->addSelect('d')
            ->where('a.drivePath LIKE :local')
            ->setParameter('local', '/%')
            ->orderBy('d.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

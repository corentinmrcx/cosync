<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttestationPaiement;
use App\Entity\Licencie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AttestationPaiement> */
class AttestationPaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttestationPaiement::class);
    }

    /**
     * Toutes les attestations d'un licencié, la plus récente d'abord — l'ordre de la
     * carte affichée sur sa fiche.
     *
     * @return AttestationPaiement[]
     */
    public function findByLicencie(Licencie $licencie): array
    {
        return $this->findBy(['licencie' => $licencie], ['generatedAt' => 'DESC']);
    }

    /**
     * La dernière émise, dont l'écran de génération pré-remplit le destinataire : la
     * deuxième attestation d'une saison vise presque toujours le même payeur.
     */
    public function findDerniereParLicencie(Licencie $licencie): ?AttestationPaiement
    {
        return $this->findOneBy(['licencie' => $licencie], ['generatedAt' => 'DESC']);
    }

    /**
     * Attestations dont le PDF n'est encore qu'en local — le stock à rattraper de
     * app:drive-retry-upload.
     *
     * @return AttestationPaiement[]
     */
    public function findWithLocalPdf(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.drivePath LIKE :local')
            ->setParameter('local', '/%')
            ->orderBy('a.generatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

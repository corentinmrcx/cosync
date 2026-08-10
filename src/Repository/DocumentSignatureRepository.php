<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\DocumentSignature;
use App\Entity\Licencie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentSignature> */
class DocumentSignatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSignature::class);
    }

    /**
     * Signatures dont le PDF n'a pas encore été uploadé sur Drive (le champ porte
     * encore un chemin local absolu). Pour le rattrapage.
     *
     * @return DocumentSignature[]
     */
    public function findWithLocalPath(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.dirigeant', 'd')
            ->leftJoin('s.licencie', 'l')
            ->addSelect('d', 'l')
            ->where('s.drivePath LIKE :local')
            ->setParameter('local', '/%')
            ->orderBy('s.signedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DocumentSignature[] */
    public function findByDirigeant(Dirigeant $dirigeant): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.document', 'doc')
            ->addSelect('doc')
            ->where('s.dirigeant = :dirigeant')
            ->setParameter('dirigeant', $dirigeant)
            ->orderBy('doc.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DocumentSignature[] */
    public function findByLicencie(Licencie $licencie): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.document', 'doc')
            ->addSelect('doc')
            ->where('s.licencie = :licencie')
            ->setParameter('licencie', $licencie)
            ->orderBy('doc.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Uuids des dirigeants ayant déjà signé ce document, sous forme de chaînes,
     * pour un test d'appartenance sans recharger les entités.
     *
     * @return string[]
     */
    public function dirigeantUuidsByDocument(DocumentSignable $document): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.dirigeant) AS uuid')
            ->where('s.document = :document')
            ->andWhere('s.dirigeant IS NOT NULL')
            ->setParameter('document', $document)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['uuid'], $rows);
    }

    public function countByDocument(DocumentSignable $document): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

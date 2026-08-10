<?php declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\DocumentSignature;
use App\Entity\Licencie;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre la signature d'un document : génération du PDF, création de la trace
 * en base, mise en file de l'archivage Drive.
 *
 * La signature manuscrite n'est jamais persistée : elle est incrustée dans le PDF,
 * qui part sur Drive avant que le fichier local ne soit supprimé.
 */
final class DocumentSignatureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly PendingUploadQueue $uploadQueue,
    ) {}

    public function signerParDirigeant(
        DocumentSignable $document,
        Dirigeant $dirigeant,
        string $signatureDataUrl,
    ): DocumentSignature {
        $signature = (new DocumentSignature())
            ->setDocument($document)
            ->setDirigeant($dirigeant);

        return $this->enregistrer(
            $signature,
            $document,
            $dirigeant->getPrenom(),
            $dirigeant->getNom(),
            (string) $dirigeant->getUuid(),
            $signatureDataUrl,
        );
    }

    public function signerParLicencie(
        DocumentSignable $document,
        Licencie $licencie,
        string $signatureDataUrl,
    ): DocumentSignature {
        $signature = (new DocumentSignature())
            ->setDocument($document)
            ->setLicencie($licencie);

        return $this->enregistrer(
            $signature,
            $document,
            $licencie->getPrenom(),
            $licencie->getNom(),
            (string) $licencie->getUuid(),
            $signatureDataUrl,
        );
    }

    /**
     * Le PDF est d'abord écrit en local ; drivePath porte donc un chemin absolu
     * jusqu'à ce que l'upload différé (kernel.terminate) lui substitue l'ID Drive.
     * L'enregistrement est flushé avant la mise en file, qui travaille sur l'id.
     */
    private function enregistrer(
        DocumentSignature $signature,
        DocumentSignable $document,
        string $prenom,
        string $nom,
        string $fileKey,
        string $signatureDataUrl,
    ): DocumentSignature {
        $localPath = $this->pdfGenerator->generateSignedDocument(
            $document,
            $prenom,
            $nom,
            $fileKey,
            $signatureDataUrl,
        );

        $signature->setDrivePath($localPath);

        $this->em->persist($signature);
        $this->em->flush();

        $this->uploadQueue->enqueueDocumentSignature($signature->getId());

        return $signature;
    }
}

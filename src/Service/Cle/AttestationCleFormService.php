<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\DTO\AttestationCleSignatureData;
use App\Entity\AttestationCle;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationClePdfService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestre la signature de l'attestation de remise de clés par un détenteur.
 *
 * La signature n'est jamais persistée : elle est incrustée dans le PDF, qui part sur
 * Drive avant que le fichier local ne soit supprimé.
 */
final class AttestationCleFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttestationClePdfService $attestationClePdf,
        private readonly CleRegistreService $registre,
        private readonly PendingUploadQueue $uploadQueue,
    ) {}

    public function submit(AttestationCle $attestation, AttestationCleSignatureData $data): void
    {
        // Le nombre de clés et la date de remise sont pris sur le registre, jamais
        // sur le client : l'attestation dit ce que le registre sait. Ils sont ensuite
        // figés sur la ligne — le document doit continuer à dire ce qu'il disait.
        $detention = $this->registre->getDetentionDe($attestation->getDetenteur());

        $attestation
            ->setSignedAt(new \DateTimeImmutable())
            ->setNbCles($detention->solde)
            ->setRemiseLe($detention->detenteurDepuis)
            ->setTokenExpiresAt(null);

        // Chemin local temporaire — l'upload Drive est différé (kernel.terminate)
        $attestation->setDrivePath($this->attestationClePdf->generateSignee($attestation, $data->signatureData));

        $this->em->flush();

        // Après le flush uniquement : feuille individuelle, puis récapitulatif régénéré.
        $this->uploadQueue->enqueueAttestationCle($attestation->getId());
        $this->uploadQueue->enqueueAttestationCleRecap($attestation->getSeason()->getId());
    }
}

<?php declare(strict_types=1);

namespace App\Service\ClubHouse;

use App\DTO\AttestationCleSignatureData;
use App\Entity\Dirigeant;
use App\Service\ClubHouse\CleRegistreService;
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

    public function submit(Dirigeant $dirigeant, AttestationCleSignatureData $data): void
    {
        // Le nombre de clés et la date de remise sont pris sur le registre, jamais
        // sur le client : l'attestation dit ce que le registre sait.
        $detention = $this->registre->getDetentionDe($dirigeant);

        // Chemin local temporaire — l'upload Drive est différé (kernel.terminate)
        $path = $this->attestationClePdf->generateSignee(
            $dirigeant,
            $data->signatureData,
            $detention->solde,
            $detention->detenteurDepuis,
        );

        $dirigeant
            ->setAttestationCleSignePath($path)
            ->setAttestationCleSignedAt(new \DateTimeImmutable())
            ->setAttestationCleTokenExpiresAt(null);

        $this->em->flush();

        // Après le flush uniquement : feuille individuelle, puis récapitulatif régénéré.
        $this->uploadQueue->enqueueDirigeantAttestationCle($dirigeant->getUuid()->toRfc4122());
        $this->uploadQueue->enqueueAttestationCleRecap($dirigeant->getSeason()->getId());
    }
}

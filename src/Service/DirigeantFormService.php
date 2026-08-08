<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\DirigeantPublicFormData;
use App\Entity\Dirigeant;
use App\Repository\DocumentSignableRepository;
use App\Service\Document\DocumentSignatureService;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationTransportPdfService;
use App\Service\Stock\DotationBesoinService;
use Doctrine\ORM\EntityManagerInterface;

final class DirigeantFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttestationTransportPdfService $attestationPdfService,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignatureService $signatureService,
        private readonly PendingUploadQueue $uploadQueue,
        private readonly DotationBesoinService $dotationBesoinService,
        private readonly DirigeantDossierCompletion $dossierCompletion,
    ) {}

    public function submit(Dirigeant $dirigeant, DirigeantPublicFormData $data): void
    {
        // Tailles + droit image : seulement pour un dirigeant non-joueur
        if ($data->tailleHaut !== null) {
            $dirigeant
                ->setTailleHaut($data->tailleHaut)
                ->setTailleBas($data->tailleBas)
                ->setPointure($data->pointure);
        }
        if ($data->autorisationPhoto !== null) {
            $dirigeant->setAutorisationPhoto($data->autorisationPhoto);
        }

        $dirigeant->setVolontaireTransport($data->volontaireTransport);

        // Si le dirigeant accepte de transporter des licenciés → générer l'attestation
        if ($data->attestationTransport !== null) {
            $attestationPath = $this->attestationPdfService->generate(
                $data->attestationTransport,
                $dirigeant->getNom(),
                $dirigeant->getPrenom(),
                $dirigeant->getSeason()->getLabel(),
            );
            // Chemin local temporaire — l'upload Drive est différé (kernel.terminate)
            $dirigeant->setAttestationTransportDriveId($attestationPath);
        }

        $dirigeant->setFormCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Documents signés : le contrôleur n'a retenu que des ids réellement attendus.
        foreach ($data->documentSignatures as $documentId => $signatureDataUrl) {
            $document = $this->documentRepo->find($documentId);

            if ($document !== null) {
                $this->signatureService->signerParDirigeant($document, $dirigeant, $signatureDataUrl);
            }
        }

        // Le lien n'est plus utilisable une fois le dossier complet
        if ($this->dossierCompletion->isComplete($dirigeant)) {
            $dirigeant->setFormTokenExpiresAt(null);
            $this->em->flush();

            $this->dotationBesoinService->recomputeForDirigeant($dirigeant);
        }

        if ($data->attestationTransport !== null) {
            $this->uploadQueue->enqueueDirigeantAttestation($dirigeant->getUuid()->toRfc4122());
        }
    }
}

<?php declare(strict_types=1);

namespace App\Service\Form;

use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use App\Repository\DocumentSignableRepository;
use App\Service\Document\DocumentSignatureService;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationTransportPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InscriptionFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignatureService $signatureService,
        private readonly AttestationTransportPdfService $attestationPdfService,
        private readonly PendingUploadQueue $uploadQueue,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function submit(Licencie $licencie, InscriptionFormData $data): void
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null) {
            throw new \LogicException('Ce licencié n\'a pas de dossier club.');
        }

        $dossier->setTailleHaut($data->tailleHaut);
        $dossier->setTailleBas($data->tailleBas);
        $dossier->setPointure($data->pointure);
        $dossier->setAutorisationPhoto($data->autorisationPhoto);
        $dossier->setAutorisationTransportDirigeants($data->autorisationTransportDirigeants);
        $dossier->setAutorisationTransportParents($data->autorisationTransportParents);
        $dossier->setAutorisationAccident($data->autorisationAccident);
        $dossier->setVolontaireTransport($data->volontaireTransport);
        $dossier->setPaymentIntentions($data->paymentIntentions);
        $dossier->setDotationChoix($data->dotationChoix);
        $dossier->setDotationPersonnalisation($data->dotationPersonnalisation);
        $dossier->setFormCompletedAt(new \DateTimeImmutable());
        $dossier->setStatus(LicenceStatus::FORM_COMPLETED);

        // Si le licencié est volontaire pour transporter des enfants → générer l'attestation
        if ($data->attestationTransport !== null) {
            $attestationPath = $this->attestationPdfService->generate(
                $data->attestationTransport,
                $licencie->getNom(),
                $licencie->getPrenom(),
                $licencie->getSeason()->getLabel(),
            );
            // Stocke le chemin local temporairement — l'upload Drive est différé
            $dossier->setAttestationTransportDriveId($attestationPath);
        }

        // Le lien n'est plus utilisable après soumission
        $licencie->setFormTokenExpiresAt(null);

        $this->em->flush();

        // Documents signés : le contrôleur n'a retenu que des ids réellement attendus.
        foreach ($data->documentSignatures as $documentId => $signatureDataUrl) {
            $document = $this->documentRepo->find($documentId);

            if ($document !== null) {
                $this->signatureService->signerParLicencie($document, $licencie, $signatureDataUrl);
            }
        }

        // Upload Drive différé (kernel.terminate)
        if ($data->attestationTransport !== null) {
            $this->uploadQueue->enqueueAttestation($dossier->getId());
        }

        $this->deleteSignatureTemp((string) $licencie->getUuid());
    }

    private function deleteSignatureTemp(string $uuid): void
    {
        $path = $this->projectDir . '/var/signatures/' . $uuid . '.png';
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

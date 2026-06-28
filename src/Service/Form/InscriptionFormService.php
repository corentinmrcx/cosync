<?php declare(strict_types=1);

namespace App\Service\Form;

use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationTransportPdfService;
use App\Service\Pdf\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InscriptionFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfGeneratorService $pdfGenerator,
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
        $dossier->setIsSigned(true);
        $dossier->setSignatureDate(new \DateTimeImmutable());
        $dossier->setFormCompletedAt(new \DateTimeImmutable());
        $dossier->setStatus(LicenceStatus::FORM_COMPLETED);

        $pdfPath = $this->pdfGenerator->generateReglementSigne($licencie, $data->signatureData);
        $dossier->setSignaturePath($pdfPath);

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

        // Uploads Drive différés (kernel.terminate)
        $this->uploadQueue->enqueue($dossier->getId());
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

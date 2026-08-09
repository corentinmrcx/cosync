<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use App\Repository\DocumentSignableRepository;
use App\Service\Payment\CotisationResolver;
use App\Service\Document\DocumentSignatureService;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Mail\MailerService;
use App\Service\Pdf\AttestationTransportPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class InscriptionFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignatureService $signatureService,
        private readonly AttestationTransportPdfService $attestationPdfService,
        private readonly PendingUploadQueue $uploadQueue,
        private readonly MailerService $mailerService,
        private readonly CotisationResolver $cotisationResolver,
        private readonly LoggerInterface $logger,
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
        $pdfsSignes = [];

        foreach ($data->documentSignatures as $documentId => $signatureDataUrl) {
            $document = $this->documentRepo->find($documentId);

            if ($document !== null) {
                $signature = $this->signatureService->signerParLicencie($document, $licencie, $signatureDataUrl);
                $chemin = $signature->getDrivePath();

                // Convention du projet : un chemin absolu = PDF encore en local, un autre
                // format = ID Drive. Seuls les fichiers locaux sont joignables au mail.
                // Le nom de fichier reprend le titre du document : le licencié reçoit
                // « Règlement intérieur.pdf », pas le nom technique préfixé de son UUID.
                if ($chemin !== null && str_starts_with($chemin, '/')) {
                    $pdfsSignes[$chemin] = $document->getTitre() . '.pdf';
                }
            }
        }

        // Upload Drive différé (kernel.terminate)
        if ($data->attestationTransport !== null) {
            $this->uploadQueue->enqueueAttestation($dossier->getId());
        }

        $this->sendConfirmation($licencie, $pdfsSignes);
    }

    /**
     * Accusé de réception, envoyé pendant la requête : après kernel.terminate les PDF
     * locaux ont été poussés sur Drive puis supprimés, il n'y aurait plus rien à joindre.
     *
     * Le dossier est déjà enregistré et les signatures prises : une panne de SMTP ne doit
     * en aucun cas faire échouer la soumission ni afficher une erreur au licencié.
     *
     * @param array<string, string> $pdfsSignes chemin local => nom de fichier lisible
     */
    private function sendConfirmation(Licencie $licencie, array $pdfsSignes): void
    {
        try {
            $this->mailerService->sendInscriptionConfirmation(
                $licencie,
                $this->cotisationResolver->resolve($licencie),
                $pdfsSignes,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Mail de confirmation d\'inscription non envoyé ({uuid}) : {message}', [
                'uuid' => (string) $licencie->getUuid(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}

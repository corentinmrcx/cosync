<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\DTO\AutorisationCompletionData;
use App\Entity\Licencie;
use App\Enum\AutorisationManquante;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationTransportPdfService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gère la complétion a posteriori des autorisations laissées vides : un dossier déjà
 * soumis peut avoir des champs d'autorisation null (ajoutés au formulaire après coup).
 * Permet de recollecter uniquement ces champs sans rejouer tout le formulaire.
 */
final class AutorisationCompletionService
{
    public function __construct(
        private readonly AttestationTransportPdfService $attestationPdfService,
        private readonly PendingUploadQueue $uploadQueue,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Autorisations applicables encore sans réponse pour ce licencié.
     * Vide tant que le formulaire initial n'a pas été complété.
     *
     * @return list<AutorisationManquante>
     */
    public function manquantes(Licencie $licencie): array
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->getFormCompletedAt() === null) {
            return [];
        }

        $estJeune = $licencie->getCategory()->isJeune();

        $sansReponse = [
            AutorisationManquante::PHOTO->value => $dossier->getAutorisationPhoto(),
            AutorisationManquante::ACCIDENT->value => $dossier->getAutorisationAccident(),
            AutorisationManquante::TRANSPORT_DIRIGEANTS->value => $dossier->getAutorisationTransportDirigeants(),
            AutorisationManquante::TRANSPORT_PARENTS->value => $dossier->getAutorisationTransportParents(),
            AutorisationManquante::VOLONTAIRE->value => $dossier->getVolontaireTransport(),
        ];

        $manquantes = [];
        foreach (AutorisationManquante::cases() as $autorisation) {
            if ($autorisation->reserveeAuxJeunes() && !$estJeune) {
                continue;
            }
            if ($sansReponse[$autorisation->value] === null) {
                $manquantes[] = $autorisation;
            }
        }

        return $manquantes;
    }

    public function hasMissing(Licencie $licencie): bool
    {
        return $this->manquantes($licencie) !== [];
    }

    /**
     * Applique les réponses de complétion : ne touche qu'aux champs fournis (non null),
     * génère l'attestation de transport si nécessaire, et consomme le lien.
     */
    public function apply(Licencie $licencie, AutorisationCompletionData $data): void
    {
        $dossier = $licencie->getDossierClub();
        if ($dossier === null) {
            throw new \LogicException('Ce licencié n\'a pas de dossier club.');
        }

        if ($data->autorisationPhoto !== null) {
            $dossier->setAutorisationPhoto($data->autorisationPhoto);
        }
        if ($data->autorisationAccident !== null) {
            $dossier->setAutorisationAccident($data->autorisationAccident);
        }
        if ($data->autorisationTransportDirigeants !== null) {
            $dossier->setAutorisationTransportDirigeants($data->autorisationTransportDirigeants);
        }
        if ($data->autorisationTransportParents !== null) {
            $dossier->setAutorisationTransportParents($data->autorisationTransportParents);
        }
        if ($data->volontaireTransport !== null) {
            $dossier->setVolontaireTransport($data->volontaireTransport);
        }

        if ($data->attestationTransport !== null) {
            $attestationPath = $this->attestationPdfService->generate(
                $data->attestationTransport,
                $licencie->getNom(),
                $licencie->getPrenom(),
                $licencie->getSeason()->getLabel(),
            );
            $dossier->setAttestationTransportDriveId($attestationPath);
        }

        // Le lien de complétion est à usage unique.
        $licencie->setFormTokenExpiresAt(null);

        $this->em->flush();

        if ($data->attestationTransport !== null) {
            $this->uploadQueue->enqueueAttestation($dossier->getId());
        }
    }
}

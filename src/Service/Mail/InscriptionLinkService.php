<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;

final class InscriptionLinkService
{
    public function __construct(
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function send(Licencie $licencie): void
    {
        if ($licencie->getEmail() === null) {
            throw new \LogicException('Impossible d\'envoyer le lien : aucune adresse email pour ce licencié.');
        }

        $licencie->setFormTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendInscriptionLink($licencie);

        $licencie->setLinkSentAt(new \DateTimeImmutable());
        // Le dossier passe de « Importé » à « Lien envoyé » sans jamais rétrograder un statut avancé.
        $dossier = $licencie->getDossierClub();
        if ($dossier?->getStatus() === LicenceStatus::IMPORTED) {
            $dossier->setStatus(LicenceStatus::LINK_SENT);
        }
        $this->em->flush();
    }

    /**
     * Renvoie un lien pour compléter uniquement les autorisations laissées vides
     * sur un dossier déjà soumis. Rouvre le token (30 jours) sans rejouer le formulaire.
     */
    public function sendCompletion(Licencie $licencie): void
    {
        if ($licencie->getEmail() === null) {
            throw new \LogicException('Impossible d\'envoyer le lien : aucune adresse email pour ce licencié.');
        }

        $licencie->setFormTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendCompletionLink($licencie);
    }
}

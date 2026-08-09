<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Dirigeant;
use App\Service\Mail\LienPublic;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Envoie le lien de signature de l'attestation de remise de clés.
 * Son token est indépendant de celui du dossier dirigeant.
 */
final class AttestationCleLinkService
{
    public function __construct(
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function send(Dirigeant $dirigeant): void
    {
        if ($dirigeant->getEmail() === null) {
            throw new \LogicException('Impossible d\'envoyer le lien : aucune adresse email pour ce dirigeant.');
        }

        $dirigeant->setAttestationCleTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendAttestationCleLink($dirigeant);
    }
}

<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Licencie;
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

        $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));
        $this->em->flush();

        $this->mailerService->sendInscriptionLink($licencie);
    }
}

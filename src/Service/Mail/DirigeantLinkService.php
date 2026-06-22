<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

final class DirigeantLinkService
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

        $dirigeant->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));
        $this->em->flush();

        $this->mailerService->sendDirigeantLink($dirigeant);
    }
}

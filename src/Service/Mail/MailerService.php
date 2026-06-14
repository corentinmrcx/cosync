<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Licencie;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendInscriptionLink(Licencie $licencie): void
    {
        $url = $this->urlGenerator->generate(
            'public_inscription_show',
            ['uuid' => $licencie->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to(new Address($licencie->getEmail(), $licencie->getNomPrenom()))
            ->subject('Finalisez votre dossier — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel())
            ->htmlTemplate('email/inscription_link.html.twig')
            ->context([
                'licencie' => $licencie,
                'url'      => $url,
            ]);

        $this->mailer->send($email);
    }
}

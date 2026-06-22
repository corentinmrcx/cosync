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

    public function sendTestEmail(string $to): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($to)
            ->subject('Test d\'envoi — Foyer de Soudron')
            ->htmlTemplate('email/test.html.twig');

        $this->mailer->send($email);
    }

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

    public function sendValidation(Licencie $licencie): void
    {
        $subject = $licencie->getCategory()->isJeune()
            ? 'Licence de ' . $licencie->getPrenom() . ' validée — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel()
            : 'Votre licence est validée — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel();

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to(new Address($licencie->getEmail(), $licencie->getNomPrenom()))
            ->subject($subject)
            ->htmlTemplate('email/validation.html.twig')
            ->context([
                'licencie' => $licencie,
            ]);

        $this->mailer->send($email);
    }
}

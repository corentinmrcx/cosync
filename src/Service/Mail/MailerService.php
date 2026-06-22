<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Service\BetaModeService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly BetaModeService $betaModeService,
        private readonly Security $security,
    ) {}

    private function resolveRecipient(Address $real): Address
    {
        if ($this->betaModeService->isActive()) {
            $user = $this->security->getUser();
            if ($user !== null) {
                return new Address($user->getUserIdentifier());
            }
        }
        return $real;
    }

    private function resolveSubject(string $subject, string $realEmail): string
    {
        if ($this->betaModeService->isActive()) {
            return "[BETA → {$realEmail}] {$subject}";
        }
        return $subject;
    }

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

        $realEmail = $licencie->getEmail();
        $subject   = 'Finalisez votre dossier — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel();

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $licencie->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/inscription_link.html.twig')
            ->context([
                'licencie' => $licencie,
                'url'      => $url,
            ]);

        $this->mailer->send($email);
    }

    public function sendDirigeantLink(Dirigeant $dirigeant): void
    {
        $url = $this->urlGenerator->generate(
            'public_dirigeant_show',
            ['uuid' => $dirigeant->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $realEmail = $dirigeant->getEmail();
        $subject   = 'Complétez votre fiche — Foyer de Soudron, saison ' . $dirigeant->getSeason()->getLabel();

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $dirigeant->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/dirigeant_link.html.twig')
            ->context([
                'dirigeant' => $dirigeant,
                'url'       => $url,
            ]);

        $this->mailer->send($email);
    }

    public function sendValidationTest(string $to, bool $isJeune): void
    {
        $subject = $isJeune
            ? 'Licence de Thomas validée — Foyer de Soudron, saison 2025-2026'
            : 'Votre licence est validée — Foyer de Soudron, saison 2025-2026';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('email/validation.html.twig')
            ->context([
                'licencie' => [
                    'prenom'   => $isJeune ? 'Thomas' : 'Kévin',
                    'nom'      => $isJeune ? 'DUPONT' : 'MARTIN',
                    'season'   => ['label' => '2025-2026'],
                    'category' => ['isJeune' => $isJeune],
                ],
            ]);

        $this->mailer->send($email);
    }

    public function sendValidation(Licencie $licencie): void
    {
        $realEmail = $licencie->getEmail();
        $subject   = $licencie->getCategory()->isJeune()
            ? 'Licence de ' . $licencie->getPrenom() . ' validée — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel()
            : 'Votre licence est validée — Foyer de Soudron, saison ' . $licencie->getSeason()->getLabel();

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $licencie->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/validation.html.twig')
            ->context([
                'licencie' => $licencie,
            ]);

        $this->mailer->send($email);
    }
}

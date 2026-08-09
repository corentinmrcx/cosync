<?php declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Mails de vérification envoyés depuis les écrans de diagnostic, avec des données factices.
 *
 * Séparé de MailerService pour que les jeux d'essai ne cohabitent pas avec les messages
 * réels, et parce que ces envois visent une adresse saisie à la main : ils ne passent
 * donc pas par la redirection du mode bêta.
 */
final class DiagMailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $emailExpediteur,
        private readonly string $nomExpediteur,
    ) {}

    public function envoyerMailDeTest(string $destinataire): void
    {
        $this->envoyer($destinataire, 'Test d\'envoi', 'email/test.html.twig');
    }

    /** Aperçu du mail de validation, pour relire sa mise en forme sans valider une vraie licence. */
    public function envoyerApercuValidation(string $destinataire, bool $estJeune): void
    {
        $this->envoyer(
            $destinataire,
            $estJeune
                ? 'Licence de Thomas validée'
                : 'Votre licence est validée',
            'email/validation.html.twig',
            [
                'licencie' => [
                    'prenom' => $estJeune ? 'Thomas' : 'Kévin',
                    'nom' => $estJeune ? 'DUPONT' : 'MARTIN',
                    'season' => ['label' => '2025-2026'],
                    'category' => ['isJeune' => $estJeune],
                ],
            ],
        );
    }

    /** @param array<string, mixed> $contexte */
    private function envoyer(string $destinataire, string $sujet, string $template, array $contexte = []): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address($this->emailExpediteur, $this->nomExpediteur))
                ->to($destinataire)
                ->subject($sujet . ' — ' . $this->nomExpediteur)
                ->htmlTemplate($template)
                ->context($contexte),
        );
    }
}

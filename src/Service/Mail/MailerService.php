<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Service\BetaModeService;
use App\Service\CotisationResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MailerService
{
    /** Au-delà, la plupart des serveurs SMTP rejettent le message : mieux vaut l'envoyer sans documents. */
    private const TAILLE_MAX_PIECES_JOINTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly BetaModeService $betaModeService,
        private readonly Security $security,
        private readonly CotisationResolver $cotisationResolver,
        private readonly LoggerInterface $logger,
    ) {}

    private function resolveRecipient(Address $real): Address
    {
        if ($this->betaModeService->isActive()) {
            $user = $this->security->getUser();
            if ($user !== null) {
                return new Address($user->getUserIdentifier());
            }
            // Pas d'utilisateur authentifié (console, async) : fallback sur DIAG_EMAIL
            // pour ne jamais laisser partir un mail vers un vrai licencié en beta.
            $diagEmail = $this->betaModeService->getRedirectEmail();
            if ($diagEmail !== '') {
                return new Address($diagEmail);
            }
            throw new \RuntimeException('Beta mode actif mais aucun destinataire de secours (DIAG_EMAIL non configuré).');
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
        $subject = 'Finalisez votre dossier — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $licencie->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/inscription_link.html.twig')
            ->context([
                'licencie' => $licencie,
                'url' => $url,
            ]);

        $this->mailer->send($email);
    }

    /**
     * Accusé de réception du formulaire, avec les instructions de paiement correspondant
     * au(x) mode(s) déclaré(s) et les documents signés en pièces jointes.
     *
     * C'est la seule trace écrite que le licencié reçoit tant qu'il n'a pas payé : le mail
     * de validation, lui, n'arrive qu'après encaissement — donc jamais pour qui ne règle pas.
     *
     * @param array<string, string> $pdfsJoints chemin local absolu => nom de fichier lisible
     */
    public function sendInscriptionConfirmation(Licencie $licencie, int $montant, array $pdfsJoints = []): void
    {
        $realEmail = $licencie->getEmail();

        if ($realEmail === null) {
            return;
        }

        $url = $this->urlGenerator->generate(
            'public_inscription_confirmation',
            ['uuid' => $licencie->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $subject = 'Inscription bien reçue — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $licencie->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/inscription_confirmation.html.twig')
            ->context([
                'licencie' => $licencie,
                'montant' => $montant,
                'intentions' => $licencie->getDossierClub()?->getPaymentIntentions() ?? [],
                'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
                'url' => $url,
                'documentsJoints' => count($pdfsJoints),
            ]);

        foreach ($this->attachementsRetenus($pdfsJoints) as $chemin => $nom) {
            $email->addPart(new DataPart(new File($chemin), $nom));
        }

        $this->mailer->send($email);
    }

    /**
     * Ne retient que les PDF réellement présents sur le disque, et renonce à tout joindre
     * au-delà du plafond : un mail rejeté par le serveur SMTP pour cause de taille ne
     * transporterait plus rien du tout, alors que les documents restent archivés sur le Drive.
     *
     * @param  array<string, string> $fichiers chemin => nom affiché
     * @return array<string, string>
     */
    private function attachementsRetenus(array $fichiers): array
    {
        $retenus = [];
        $total = 0;

        foreach ($fichiers as $chemin => $nom) {
            if (!is_file($chemin)) {
                $this->logger->warning('Mail de confirmation : pièce jointe introuvable, ignorée ({chemin}).', [
                    'chemin' => $chemin,
                ]);
                continue;
            }

            $total += (int) filesize($chemin);
            $retenus[$chemin] = $nom;
        }

        if ($total > self::TAILLE_MAX_PIECES_JOINTES) {
            $this->logger->warning('Mail de confirmation : {taille} octets de pièces jointes, envoi sans documents.', [
                'taille' => $total,
            ]);

            return [];
        }

        return $retenus;
    }

    public function sendCompletionLink(Licencie $licencie): void
    {
        $url = $this->urlGenerator->generate(
            'public_inscription_completer',
            ['uuid' => $licencie->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $realEmail = $licencie->getEmail();
        $subject = 'Une précision à apporter à votre dossier — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $licencie->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/completion_link.html.twig')
            ->context([
                'licencie' => $licencie,
                'url' => $url,
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
        $subject = 'Finalisez votre dossier dirigeant — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $dirigeant->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/dirigeant_link.html.twig')
            ->context([
                'dirigeant' => $dirigeant,
                'url' => $url,
            ]);

        $this->mailer->send($email);
    }

    public function sendAttestationCleLink(Dirigeant $dirigeant): void
    {
        $url = $this->urlGenerator->generate(
            'public_attestation_cle_show',
            ['uuid' => $dirigeant->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $realEmail = $dirigeant->getEmail();
        $subject = 'Attestation de remise de clés à signer — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($this->resolveRecipient(new Address($realEmail, $dirigeant->getNomPrenom())))
            ->subject($this->resolveSubject($subject, $realEmail))
            ->htmlTemplate('email/attestation_cle_link.html.twig')
            ->context([
                'dirigeant' => $dirigeant,
                'url' => $url,
            ]);

        $this->mailer->send($email);
    }

    public function sendValidationTest(string $to, bool $isJeune): void
    {
        $subject = $isJeune
            ? 'Licence de Thomas validée — Foyer de Soudron'
            : 'Votre licence est validée — Foyer de Soudron';

        $email = (new TemplatedEmail())
            ->from(new Address('soudron.fr@marne.lgef.fr', 'Foyer de Soudron'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('email/validation.html.twig')
            ->context([
                'licencie' => [
                    'prenom' => $isJeune ? 'Thomas' : 'Kévin',
                    'nom' => $isJeune ? 'DUPONT' : 'MARTIN',
                    'season' => ['label' => '2025-2026'],
                    'category' => ['isJeune' => $isJeune],
                ],
            ]);

        $this->mailer->send($email);
    }

    public function sendValidation(Licencie $licencie): void
    {
        $realEmail = $licencie->getEmail();
        $subject = $licencie->getCategory()->isJeune()
            ? 'Licence de ' . $licencie->getPrenom() . ' validée — Foyer de Soudron'
            : 'Votre licence est validée — Foyer de Soudron';

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

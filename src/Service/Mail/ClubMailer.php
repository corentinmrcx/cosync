<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\BetaModeService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Point de passage unique des mails du club : expéditeur, redirection en mode bêta et envoi.
 *
 * En mode bêta, aucun mail ne part vers un vrai licencié : le destinataire est remplacé et
 * le sujet préfixé par l'adresse réellement visée, pour que le test reste vérifiable.
 */
final class ClubMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly BetaModeService $betaModeService,
        private readonly Security $security,
        private readonly string $emailExpediteur,
        private readonly string $nomExpediteur,
    ) {}

    /**
     * @param array<string, mixed>  $contexte
     * @param array<string, string> $piecesJointes chemin absolu => nom de fichier lisible
     */
    public function envoyer(
        Address $destinataire,
        string $sujet,
        string $template,
        array $contexte = [],
        array $piecesJointes = [],
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->emailExpediteur, $this->nomExpediteur))
            ->to($this->destinataireEffectif($destinataire))
            ->subject($this->sujetEffectif($sujet, $destinataire->getAddress()))
            ->htmlTemplate($template)
            ->context($contexte);

        foreach ($piecesJointes as $chemin => $nom) {
            $email->addPart(new DataPart(new File($chemin), $nom));
        }

        $this->mailer->send($email);
    }

    private function destinataireEffectif(Address $reel): Address
    {
        if (!$this->betaModeService->isActive()) {
            return $reel;
        }

        $user = $this->security->getUser();
        if ($user !== null) {
            return new Address($user->getUserIdentifier());
        }

        // Hors requête authentifiée (console, tâche planifiée) : repli sur DIAG_EMAIL, pour
        // ne jamais laisser partir un mail vers un vrai licencié en bêta.
        $emailDiagnostic = $this->betaModeService->getRedirectEmail();
        if ($emailDiagnostic === '') {
            throw new \RuntimeException('Beta mode actif mais aucun destinataire de secours (DIAG_EMAIL non configuré).');
        }

        return new Address($emailDiagnostic);
    }

    /** Tous les mails du club portent son nom en fin de sujet : inutile de le répéter à l'appel. */
    private function sujetEffectif(string $sujet, string $emailReel): string
    {
        $sujet .= ' — ' . $this->nomExpediteur;

        return $this->betaModeService->isActive() ? "[BETA → {$emailReel}] {$sujet}" : $sujet;
    }
}

<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Entity\User;
use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Service\Ops\BetaModeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Point de passage unique des mails du club : expéditeur, redirection en mode bêta, envoi
 * et **journalisation**.
 *
 * En mode bêta, aucun mail ne part vers un vrai licencié : le destinataire est remplacé et
 * le sujet préfixé par l'adresse réellement visée, pour que le test reste vérifiable.
 *
 * L'expéditeur et l'adresse de réponse sont volontairement dissociés : le premier doit vivre
 * sur le domaine authentifié chez Brevo pour que la signature DKIM tienne, le second désigne
 * la boîte du foot, que personne n'a besoin d'authentifier. Cf. `club.email_reponse`.
 *
 * **Le journal s'écrit ici, et nulle part ailleurs.** Chaque envoi laisse un `EnvoiMail`,
 * d'où l'exigence d'un `TypeMail` à l'appel : aucun mail ne peut plus partir en silence.
 * Le confier aux services appelants les ferait diverger, et c'est le côté oublié qui
 * enverrait le mail invisible — le défaut qu'on corrige, `SignatureRelanceService` en tête.
 */
final class ClubMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly BetaModeService $betaModeService,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $emailExpediteur,
        private readonly string $nomExpediteur,
        private readonly string $emailReponse,
    ) {}

    /**
     * @param array<string, mixed>  $contexte
     * @param array<string, string> $piecesJointes chemin absolu => nom de fichier lisible
     * @param ?OrigineEnvoi         $origine       à préciser pour les seuls mails qui partent
     *                                             de plusieurs façons — la relance, envoyée
     *                                             tantôt par le cron, tantôt par un admin
     */
    public function envoyer(
        TypeMail $type,
        Licencie|Dirigeant|Detenteur|null $personne,
        Address $destinataire,
        string $sujet,
        string $template,
        array $contexte = [],
        array $piecesJointes = [],
        ?OrigineEnvoi $origine = null,
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->emailExpediteur, $this->nomExpediteur))
            ->replyTo(new Address($this->emailReponse, $this->nomExpediteur))
            ->to($this->destinataireEffectif($destinataire))
            ->subject($this->sujetEffectif($sujet, $destinataire->getAddress()))
            ->htmlTemplate($template)
            ->context($contexte);

        foreach ($piecesJointes as $chemin => $nom) {
            $email->addPart(new DataPart(new File($chemin), $nom));
        }

        $this->mailer->send($email);

        // Après l'envoi seulement : une ligne écrite sur un envoi qui a échoué ferait
        // croire la personne relancée, et empêcherait la vraie relance de partir.
        $this->journaliser($type, $personne, $destinataire, $origine ?? $type->origineParDefaut());
    }

    /**
     * L'adresse journalisée est celle réellement visée, jamais la redirection du mode bêta :
     * un test ne doit pas laisser dans l'historique la trace d'un mail au développeur.
     */
    private function journaliser(
        TypeMail $type,
        Licencie|Dirigeant|Detenteur|null $personne,
        Address $destinataire,
        OrigineEnvoi $origine,
    ): void {
        $envoi = (new EnvoiMail($type, $origine, $destinataire->getAddress()))
            ->rattacherA($personne);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $envoi->setDeclenchePar($user);
        }

        try {
            $this->em->persist($envoi);
            $this->em->flush();
        } catch (\Throwable $e) {
            // Le mail est parti : perdre sa trace est regrettable, le faire repartir en
            // erreur 500 le serait davantage. On le signale et on continue.
            $this->logger->error('Envoi de mail non journalisé ({type} → {email}) : {message}', [
                'type' => $type->value,
                'email' => $destinataire->getAddress(),
                'message' => $e->getMessage(),
            ]);
        }
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

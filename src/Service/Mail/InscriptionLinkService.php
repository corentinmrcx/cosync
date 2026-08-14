<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\DTO\EnvoiGroupeResultat;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
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

        $licencie->setFormTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendInscriptionLink($licencie);

        $licencie->setLinkSentAt(new \DateTimeImmutable());
        // Le dossier passe de « Importé » à « Lien envoyé » sans jamais rétrograder un statut avancé.
        $dossier = $licencie->getDossierClub();
        if ($dossier?->getStatus() === LicenceStatus::IMPORTED) {
            $dossier->setStatus(LicenceStatus::LINK_SENT);
        }
        $this->em->flush();
    }

    /**
     * Envoi groupé, une seule fois par licencié : l'import n'envoie plus rien de lui-même,
     * c'est ici que l'admin déclenche le départ des liens une fois la saison en place.
     *
     * La sélection est repassée au crible de la liste réellement en attente plutôt que crue
     * telle quelle : un uuid ajouté au formulaire ne peut pas faire écrire à quelqu'un qui
     * n'était pas proposé.
     *
     * Un échec SMTP n'interrompt pas la boucle : les liens partis restent partis, le compte
     * rendu dit combien sont à rejouer.
     *
     * @param Licencie[] $licencies    tous les licenciés en attente d'un lien
     * @param string[]   $uuidsRetenus ceux que l'admin a laissés cochés
     */
    public function envoyerEnMasse(array $licencies, array $uuidsRetenus): EnvoiGroupeResultat
    {
        $retenus = array_flip($uuidsRetenus);

        $envoyes = 0;
        $echecs = 0;
        $sansEmail = 0;
        $nonRetenus = 0;

        foreach ($licencies as $licencie) {
            if ($licencie->getEmail() === null) {
                ++$sansEmail;

                continue;
            }

            if (!isset($retenus[(string) $licencie->getUuid()])) {
                ++$nonRetenus;

                continue;
            }

            try {
                $this->send($licencie);
                ++$envoyes;
            } catch (\Throwable) {
                ++$echecs;
            }
        }

        return new EnvoiGroupeResultat($envoyes, $echecs, $sansEmail, $nonRetenus);
    }

    /**
     * Renvoie un lien pour compléter uniquement les autorisations laissées vides
     * sur un dossier déjà soumis. Rouvre le token (30 jours) sans rejouer le formulaire.
     */
    public function sendCompletion(Licencie $licencie): void
    {
        if ($licencie->getEmail() === null) {
            throw new \LogicException('Impossible d\'envoyer le lien : aucune adresse email pour ce licencié.');
        }

        $licencie->setFormTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendCompletionLink($licencie);
    }
}

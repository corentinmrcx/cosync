<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\DTO\EnvoiGroupeResultat;
use App\DTO\RelanceResultat;
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

        $dirigeant->setFormTokenExpiresAt(LienPublic::expiration());
        $this->em->flush();

        $this->mailerService->sendDirigeantLink($dirigeant);

        // Après l'envoi seulement : une date posée d'avance ferait croire à un mail parti
        // alors que le SMTP a refusé, et sortirait le dirigeant de l'écran d'envoi groupé.
        $dirigeant->setLinkSentAt($dirigeant->getLinkSentAt() ?? new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Envoi groupé, une seule fois par dirigeant : ni l'import ni la création manuelle
     * n'écrivent d'eux-mêmes, c'est ici que l'admin décide du départ des liens.
     *
     * La sélection est repassée au crible de la liste réellement en attente plutôt que crue
     * telle quelle : un uuid ajouté au formulaire ne peut pas faire écrire à quelqu'un qui
     * n'était pas proposé.
     *
     * Un échec SMTP n'interrompt pas la boucle : les liens partis restent partis, le compte
     * rendu dit combien sont à rejouer.
     *
     * @param Dirigeant[] $dirigeants   tous les dirigeants en attente d'un lien
     * @param string[]    $uuidsRetenus ceux que l'admin a laissés cochés
     */
    public function envoyerEnMasse(array $dirigeants, array $uuidsRetenus): EnvoiGroupeResultat
    {
        $retenus = array_flip($uuidsRetenus);

        $envoyes = 0;
        $echecs = 0;
        $sansEmail = 0;
        $nonRetenus = 0;

        foreach ($dirigeants as $dirigeant) {
            if ($dirigeant->getEmail() === null) {
                ++$sansEmail;

                continue;
            }

            if (!isset($retenus[(string) $dirigeant->getUuid()])) {
                ++$nonRetenus;

                continue;
            }

            try {
                $this->send($dirigeant);
                ++$envoyes;
            } catch (\Throwable) {
                ++$echecs;
            }
        }

        return new EnvoiGroupeResultat($envoyes, $echecs, $sansEmail, $nonRetenus);
    }

    /**
     * Relance en masse : les dirigeants sans adresse sont ignorés, pas bloquants.
     *
     * @param Dirigeant[] $dirigeants
     */
    public function relancerEnMasse(array $dirigeants): RelanceResultat
    {
        $envoyes = 0;
        $sansEmail = 0;

        foreach ($dirigeants as $dirigeant) {
            if ($dirigeant->getEmail() === null) {
                ++$sansEmail;
                continue;
            }

            $this->send($dirigeant);
            ++$envoyes;
        }

        return new RelanceResultat($envoyes, $sansEmail);
    }
}

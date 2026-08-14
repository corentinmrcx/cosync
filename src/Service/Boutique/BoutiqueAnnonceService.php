<?php declare(strict_types=1);

namespace App\Service\Boutique;

use App\DTO\EnvoiGroupeResultat;
use App\Entity\Licencie;
use App\Service\Mail\MailerService;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annonce de la boutique du club aux licenciés déjà inscrits.
 *
 * Le club ouvre sa boutique quelques jours après ses licences : l'annonce ne peut donc pas
 * s'accrocher à la soumission du formulaire, qui a lieu avant. C'est un envoi groupé, décidé
 * une fois la boutique ouverte, à une population dont le dossier est déjà complet.
 */
final class BoutiqueAnnonceService
{
    public function __construct(
        private readonly MailerService $mailerService,
        private readonly ClubSettingsService $clubSettings,
        private readonly EntityManagerInterface $em,
    ) {}

    public function annoncer(Licencie $licencie): void
    {
        if ($licencie->getEmail() === null) {
            throw new \LogicException('Impossible d\'annoncer la boutique : aucune adresse email pour ce licencié.');
        }

        $this->mailerService->sendBoutique($licencie);

        $licencie->setBoutiqueAnnonceeAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Envoi groupé, une seule fois par licencié — c'est `boutiqueAnnonceeAt` qui l'atteste,
     * pas l'état du dossier.
     *
     * La sélection est repassée au crible de la liste réellement proposée plutôt que crue
     * telle quelle : un uuid ajouté au formulaire ne peut pas faire écrire à quelqu'un qui
     * n'était pas dans l'écran.
     *
     * Un échec SMTP n'interrompt pas la boucle : les mails partis restent partis, et le
     * licencié qui n'a rien reçu garde `boutiqueAnnonceeAt` vide — il ressortira dans la
     * liste au prochain passage.
     *
     * @param Licencie[] $licencies    tous ceux que l'écran proposait
     * @param string[]   $uuidsRetenus ceux que l'admin a laissés cochés
     */
    public function envoyerEnMasse(array $licencies, array $uuidsRetenus): EnvoiGroupeResultat
    {
        if (!$this->clubSettings->get()->aBoutique()) {
            throw new \LogicException('Impossible d\'annoncer une boutique qui n\'est pas ouverte.');
        }

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
                $this->annoncer($licencie);
                ++$envoyes;
            } catch (\Throwable) {
                ++$echecs;
            }
        }

        return new EnvoiGroupeResultat($envoyes, $echecs, $sansEmail, $nonRetenus);
    }
}

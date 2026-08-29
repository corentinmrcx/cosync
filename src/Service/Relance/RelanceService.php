<?php declare(strict_types=1);

namespace App\Service\Relance;

use App\DTO\EnvoiGroupeResultat;
use App\DTO\RelanceDue;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\EtapeRelance;
use App\Enum\OrigineEnvoi;
use App\Service\Mail\LienPublic;
use App\Service\Mail\MailerService;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Relancer les licences non soldées — par le cron, ou depuis l'écran groupé.
 *
 * Les deux chemins passent par {@see RelanceResolver} : la liste que l'écran affiche est
 * exactement celle que le robot enverrait. Les faire diverger reviendrait à ce que le cron
 * écrive à quelqu'un que l'admin n'avait pas vu.
 *
 * Deux différences entre les deux chemins, et deux seulement :
 *
 * - **L'interrupteur ne vaut que pour l'automatique.** L'écran doit rester utilisable robot
 *   éteint : c'est même la seule façon de relancer tant qu'on ne l'a pas allumé.
 * - **L'origine journalisée diffère** — c'est ce qui permettra de dire, sur une fiche, si
 *   la dernière relance venait du club ou de la machine.
 */
final class RelanceService
{
    public function __construct(
        private readonly RelanceResolver $resolver,
        private readonly MailerService $mailerService,
        private readonly ClubSettingsService $clubSettings,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Passe automatique quotidienne. Ne fait rien tant que l'admin n'a pas allumé
     * l'interrupteur : un robot qui écrit à tout un effectif ne s'active pas par défaut.
     */
    public function envoyerLesDues(Season $season, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): EnvoiGroupeResultat
    {
        if (!$this->clubSettings->get()->isRelanceActive()) {
            return new EnvoiGroupeResultat(0, 0, 0, 0);
        }

        return $this->envoyer($this->resolver->dues($season, $maintenant), OrigineEnvoi::AUTOMATIQUE);
    }

    /**
     * Envoi groupé décidé écran en main.
     *
     * La sélection est repassée au crible de la liste réellement due plutôt que crue telle
     * quelle : un uuid ajouté au formulaire ne peut pas faire écrire à quelqu'un qui n'était
     * pas proposé — et surtout pas à quelqu'un qui vient d'être relancé.
     *
     * @param string[] $uuidsRetenus
     */
    public function envoyerEnMasse(Season $season, array $uuidsRetenus, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): EnvoiGroupeResultat
    {
        $retenus = array_flip($uuidsRetenus);
        $toutes = $this->resolver->dues($season, $maintenant);

        $dues = array_values(array_filter(
            $toutes,
            static fn (RelanceDue $due): bool => isset($retenus[$due->uuid()]),
        ));

        return $this->envoyer($dues, OrigineEnvoi::ADMIN, count($toutes) - count($dues));
    }

    /**
     * Un échec SMTP n'interrompt pas la boucle : les relances parties restent parties, et
     * celui qui n'a rien reçu n'a pas de ligne au journal — il ressortira au passage suivant.
     *
     * @param RelanceDue[] $dues
     */
    private function envoyer(array $dues, OrigineEnvoi $origine, int $nonRetenus = 0): EnvoiGroupeResultat
    {
        $envoyes = 0;
        $echecs = 0;

        foreach ($dues as $due) {
            try {
                $this->relancer($due, $origine);
                ++$envoyes;
            } catch (\Throwable) {
                ++$echecs;
            }
        }

        // `sansEmail` reste à zéro : le resolver écarte en amont ceux qu'on ne peut pas
        // joindre. Ils n'ont jamais figuré dans la liste, les compter ici serait mentir.
        return new EnvoiGroupeResultat($envoyes, $echecs, 0, $nonRetenus);
    }

    /**
     * Relance à l'unité, depuis une fiche.
     *
     * Ne passe par aucune des conditions du resolver — ni délai, ni plafond : c'est un acte
     * délibéré, et la fiche affiche le dernier contact juste au-dessus du bouton. On montre
     * l'information plutôt que de bloquer la personne qui la lit.
     *
     * Elle compte en revanche **dans** le plafond : elle est journalisée comme les autres,
     * et repousse donc d'autant la relance automatique suivante.
     */
    public function relancerUnLicencie(Licencie $licencie): void
    {
        $etape = $this->resolver->etapePour($licencie);

        if ($etape === null) {
            throw new \LogicException('Cette licence est soldée : il n\'y a plus rien à relancer.');
        }

        if ($licencie->getEmail() === null) {
            throw new \LogicException('Impossible de relancer : aucune adresse email pour ce licencié.');
        }

        $this->envoyerA($licencie, $etape, OrigineEnvoi::ADMIN);
    }

    /**
     * Une relance de dossier rouvre le lien avant de l'envoyer : sans cela, le mail
     * renverrait vers un jeton expiré depuis longtemps — la relance a lieu, par
     * construction, des semaines après le premier envoi. La relance de paiement n'en a pas
     * besoin, la page de confirmation n'étant protégée par aucun jeton.
     */
    public function relancer(RelanceDue $due, OrigineEnvoi $origine): void
    {
        $this->envoyerA($due->licencie, $due->etape, $origine);
    }

    private function envoyerA(Licencie $licencie, EtapeRelance $etape, OrigineEnvoi $origine): void
    {
        if ($etape === EtapeRelance::DOSSIER) {
            $licencie->setFormTokenExpiresAt(LienPublic::expiration());
            $this->em->flush();
        }

        $this->mailerService->sendRelance($licencie, $etape, $origine);
    }
}

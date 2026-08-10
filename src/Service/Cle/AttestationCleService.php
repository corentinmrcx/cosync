<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\DTO\CleCampagneResultat;
use App\Entity\AttestationCle;
use App\Entity\Detenteur;
use App\Entity\Season;
use App\Repository\AttestationCleRepository;
use App\Service\Mail\LienPublic;
use App\Service\Mail\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Demande de signature de l'attestation de remise, saison par saison.
 *
 * L'engagement se rejoue chaque année : au changement de saison, tous les
 * détenteurs y retombent « non signée ». La campagne est déclenchée à la main —
 * aucun mail ne part sans décision de l'admin.
 */
final class AttestationCleService
{
    public function __construct(
        private readonly AttestationCleRepository $attestationRepo,
        private readonly CleRegistrePresenter $presenter,
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Envoie le lien de signature à tous les détenteurs dont l'engagement de la
     * saison ne couvre pas la détention.
     */
    public function lancerCampagne(Season $season): CleCampagneResultat
    {
        $envoyes = 0;
        $sansEmail = [];
        $echecs = [];

        foreach ($this->presenter->enAttenteDeSignature($this->presenter->lignes($season)) as $ligne) {
            $detenteur = $ligne->detenteur();

            if ($detenteur->getEmail() === null || $detenteur->getEmail() === '') {
                $sansEmail[] = $detenteur->getNomPrenom();
                continue;
            }

            // Un envoi qui échoue ne doit pas priver les suivants du leur.
            try {
                $this->demander($detenteur, $season);
                ++$envoyes;
            } catch (\Throwable $e) {
                $this->logger->error('Échec envoi du lien d\'attestation de clés à {personne} : {message}', [
                    'personne' => $detenteur->getNomPrenom(),
                    'message' => $e->getMessage(),
                ]);
                $echecs[] = $detenteur->getNomPrenom();
            }
        }

        return new CleCampagneResultat($envoyes, $sansEmail, $echecs);
    }

    /**
     * Ouvre (ou rouvre) la demande de signature de la saison et envoie le lien.
     *
     * Une demande en attente est réutilisée — on ne veut pas semer des liens
     * concurrents. Une attestation déjà signée n'est jamais rouverte : la nouvelle
     * demande crée une ligne à part, les deux PDF faisant foi à leur date.
     *
     * @throws \LogicException si le détenteur n'a pas d'adresse mail
     */
    public function demander(Detenteur $detenteur, Season $season): AttestationCle
    {
        if ($detenteur->getEmail() === null || $detenteur->getEmail() === '') {
            throw new \LogicException(sprintf('Impossible d\'envoyer le lien : aucune adresse mail pour %s.', $detenteur->getNomPrenom()));
        }

        $attestation = $this->attestationRepo->findDerniereDe($detenteur, $season);

        if ($attestation === null || $attestation->estSignee()) {
            $attestation = (new AttestationCle())
                ->setDetenteur($detenteur)
                ->setSeason($season);

            $this->em->persist($attestation);
        }

        $attestation
            ->setDemandeEnvoyeeLe(new \DateTimeImmutable())
            ->setTokenExpiresAt(LienPublic::expiration());

        $this->em->flush();

        $this->mailerService->sendAttestationCleLink($attestation);

        return $attestation;
    }
}

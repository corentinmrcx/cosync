<?php declare(strict_types=1);

namespace App\Service\Mail;

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

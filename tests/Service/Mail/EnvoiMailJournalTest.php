<?php declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Repository\EnvoiMailRepository;
use App\Service\Mail\ClubMailer;
use App\Service\Mail\MailerService;
use App\Service\Ops\BetaModeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Le journal des mails envoyés.
 *
 * Il existe pour une raison précise : `linkSentAt` est écrasé à chaque renvoi, une relance
 * ne se voyait donc nulle part — et un admin pouvait réécrire à quelqu'un que le club
 * venait de relancer. Les tests ci-dessous verrouillent les trois règles qui font qu'on
 * peut s'y fier : une ligne par envoi réel, aucune sur un envoi manqué, et l'adresse
 * réellement visée même quand le mode bêta détourne le message.
 */
final class EnvoiMailJournalTest extends KernelTestCase
{
    public function testChaqueMailEnvoyeLaisseUneLigne(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        self::getContainer()->get(MailerService::class)->sendInscriptionLink($licencie);

        $envois = $this->journalDe($licencie);
        self::assertCount(1, $envois);
        self::assertSame(TypeMail::INSCRIPTION_LINK, $envois[0]->getType());
        self::assertSame('kevin.martin@example.test', $envois[0]->getDestinataireEmail());
        self::assertSame($licencie->getUuid(), $envois[0]->getLicencie()?->getUuid());
    }

    /** Le défaut d'origine : deux envois du même lien font deux lignes, pas une écrasée. */
    public function testUnRenvoiAjouteUneLigneAuLieuDEcraserLaPrecedente(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        $mailer = self::getContainer()->get(MailerService::class);
        $mailer->sendInscriptionLink($licencie);
        $mailer->sendInscriptionLink($licencie);

        self::assertCount(2, $this->journalDe($licencie));
    }

    /**
     * Une ligne écrite sur un envoi qui a échoué ferait croire la personne relancée, et
     * empêcherait la vraie relance de partir — pire que l'absence de trace.
     */
    public function testUnEchecDEnvoiNeLaisseAucuneTrace(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        $this->expectException(TransportException::class);

        try {
            $this->clubMailerEnPanne()->envoyer(
                TypeMail::INSCRIPTION_LINK,
                $licencie,
                new Address('kevin.martin@example.test'),
                'Finalisez votre dossier',
                'email/inscription_link.html.twig',
                ['licencie' => $licencie, 'url' => 'https://example.test/inscription'],
            );
        } finally {
            self::assertCount(0, $this->journalDe($licencie));
        }
    }

    /**
     * En mode bêta le message est détourné vers le développeur. Journaliser cette adresse
     * laisserait dans l'historique du licencié la trace d'un mail qu'il n'a pas reçu — et
     * pire, un mail qui n'a jamais visé quelqu'un d'autre que le testeur.
     */
    public function testLeModeBetaNeChangePasLAdresseJournalisee(): void
    {
        self::bootKernel();
        $beta = self::getContainer()->get(BetaModeService::class);
        $licencie = $this->seedLicencie();

        $beta->enable();

        try {
            self::getContainer()->get(MailerService::class)->sendInscriptionLink($licencie);
        } finally {
            $beta->disable();
        }

        $envois = $this->journalDe($licencie);
        self::assertCount(1, $envois);
        self::assertSame('kevin.martin@example.test', $envois[0]->getDestinataireEmail());
    }

    /** L'accusé de réception part parce que le licencié a soumis, pas parce qu'un admin a cliqué. */
    public function testLOrigineParDefautSuitLeTypeDeMail(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        self::getContainer()->get(MailerService::class)->sendInscriptionConfirmation($licencie, 85);

        self::assertSame(OrigineEnvoi::LICENCIE, $this->journalDe($licencie)[0]->getOrigine());
    }

    /* ── Outils ── */

    /** @return \App\Entity\EnvoiMail[] */
    private function journalDe(Licencie $licencie): array
    {
        return self::getContainer()->get(EnvoiMailRepository::class)->pourLicencie($licencie);
    }

    /** Un ClubMailer réel, monté sur un transport qui échoue systématiquement. */
    private function clubMailerEnPanne(): ClubMailer
    {
        $transport = new class implements MailerInterface {
            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new TransportException('SMTP indisponible');
            }
        };

        return new ClubMailer(
            $transport,
            self::getContainer()->get(BetaModeService::class),
            self::getContainer()->get(Security::class),
            self::getContainer()->get(EntityManagerInterface::class),
            new NullLogger(),
            'contact@example.test',
            'Foyer de Soudron',
            'reponse@example.test',
        );
    }

    private function seedLicencie(): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Sénior')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail('kevin.martin@example.test')
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::IMPORTED);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $licencie;
    }
}

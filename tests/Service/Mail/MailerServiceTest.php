<?php declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Service\Mail\InscriptionLinkService;
use App\Service\Mail\MailerService;
use App\Service\Ops\BetaModeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * Les mails sortants : destinataire, sujet, et surtout le lien qu'ils transportent.
 *
 * Le mode beta est le mécanisme le plus risqué du lot — tant qu'il est actif, aucun
 * licencié ne reçoit rien, tout part vers DIAG_EMAIL. Il doit être prouvé dans ses
 * deux états, puisque l'ouverture aux licenciés consiste précisément à le désactiver.
 */
final class MailerServiceTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    protected function tearDown(): void
    {
        // Le verrou beta est un fichier : il survivrait au rollback de transaction.
        self::getContainer()->get(BetaModeService::class)->disable();
        parent::tearDown();
    }

    public function testLeLienDInscriptionPartAuLicencieAvecSonUuid(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        self::getContainer()->get(MailerService::class)->sendInscriptionLink($licencie);

        $messages = $this->messagesEnvoyes();
        self::assertCount(1, $messages);

        $message = $messages[0];
        self::assertSame('kevin.martin@example.test', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('Finalisez votre dossier', $message->getSubject());
        self::assertStringContainsString(
            '/inscription/' . $licencie->getUuid(),
            (string) $message->getHtmlBody(),
            'Le mail doit contenir le lien personnel du licencié',
        );
    }

    public function testLeMailDeValidationEstAdresseAuLicencie(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        self::getContainer()->get(MailerService::class)->sendValidation($licencie);

        $message = $this->messagesEnvoyes()[0];
        self::assertSame('kevin.martin@example.test', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('validée', $message->getSubject());
    }

    /**
     * Un jeune : le mail s'adresse aux parents et nomme l'enfant, alors qu'un sénior
     * est tutoyé directement. Une inversion enverrait « Votre licence » aux parents.
     */
    public function testLeSujetDeValidationDistingueUnJeuneDUnSenior(): void
    {
        self::bootKernel();
        $mailer = self::getContainer()->get(MailerService::class);

        $mailer->sendValidation($this->seedLicencie(code: 'U11'));
        self::assertStringContainsString('Licence de Kevin validée', $this->messagesEnvoyes()[0]->getSubject());
    }

    /* ── Délivrabilité ── */

    /**
     * L'expéditeur porte la signature DKIM, donc l'alignement DMARC, donc le passage en
     * boîte de réception : il doit rester sur le domaine authentifié chez Brevo. Le Reply-To
     * n'est soumis à aucune vérification — c'est lui qui ramène les réponses des licenciés
     * sur la boîte du foot, hébergée sur un domaine que Brevo ne peut pas signer.
     */
    public function testLExpediteurEstAuthentifiableEtLesReponsesVontALaBoiteDuFoot(): void
    {
        self::bootKernel();

        self::getContainer()->get(MailerService::class)->sendInscriptionLink($this->seedLicencie());

        $message = $this->messagesEnvoyes()[0];
        self::assertSame('contact@foyerdesoudron.fr', $message->getFrom()[0]->getAddress());
        self::assertSame('soudron.fr@marne.lgef.fr', $message->getReplyTo()[0]->getAddress());
    }

    /**
     * Symfony dérive la version texte du HTML, mais son convertisseur par défaut est un
     * `strip_tags` qui perd les `href` : sur un mail dont l'unique raison d'être est un lien,
     * la version texte n'en gardait que le libellé du bouton. `league/html-to-markdown`
     * conserve les URL — d'où cette dépendance, sans laquelle le test retombe.
     */
    public function testLaVersionTexteConserveLeLienDInscription(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie();

        self::getContainer()->get(MailerService::class)->sendInscriptionLink($licencie);

        self::assertStringContainsString(
            '/inscription/' . $licencie->getUuid(),
            (string) $this->messagesEnvoyes()[0]->getTextBody(),
        );
    }

    /* ── Mode beta ── */

    public function testEnModeBetaAucunMailNAtteintLeLicencie(): void
    {
        self::bootKernel();
        self::getContainer()->get(BetaModeService::class)->enable();

        $licencie = $this->seedLicencie();
        self::getContainer()->get(MailerService::class)->sendInscriptionLink($licencie);

        $message = $this->messagesEnvoyes()[0];
        $destinataire = $message->getTo()[0]->getAddress();

        self::assertNotSame('kevin.martin@example.test', $destinataire, 'Le mail ne doit jamais atteindre le licencié en beta');
        self::assertSame($this->diagEmail(), $destinataire);
        self::assertStringStartsWith('[BETA → kevin.martin@example.test]', $message->getSubject());
    }

    public function testHorsModeBetaLeSujetNEstPasPrefixe(): void
    {
        self::bootKernel();
        self::getContainer()->get(BetaModeService::class)->disable();

        self::getContainer()->get(MailerService::class)->sendInscriptionLink($this->seedLicencie());

        self::assertStringNotContainsString('[BETA', $this->messagesEnvoyes()[0]->getSubject());
    }

    /* ── InscriptionLinkService ── */

    public function testLEnvoiDuLienOuvreUneFenetreDe30JoursEtFaitAvancerLeStatut(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie(statut: LicenceStatus::IMPORTED);

        self::getContainer()->get(InscriptionLinkService::class)->send($licencie);

        self::assertTrue($licencie->isFormTokenValid());
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+30 days'))->getTimestamp(),
            $licencie->getFormTokenExpiresAt()->getTimestamp(),
            60,
        );
        self::assertNotNull($licencie->getLinkSentAt());
        self::assertSame(LicenceStatus::LINK_SENT, $licencie->getDossierClub()->getStatus());
        self::assertCount(1, $this->messagesEnvoyes());
    }

    /** Un renvoi ne doit pas rétrograder un dossier déjà rempli au statut « lien envoyé ». */
    public function testUnRenvoiNeRetrogradePasUnStatutAvance(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie(statut: LicenceStatus::FORM_COMPLETED);

        self::getContainer()->get(InscriptionLinkService::class)->send($licencie);

        self::assertSame(LicenceStatus::FORM_COMPLETED, $licencie->getDossierClub()->getStatus());
    }

    public function testUnLicencieSansEmailNeDeclenchePasDEnvoi(): void
    {
        self::bootKernel();
        $licencie = $this->seedLicencie(email: null);

        $this->expectException(\LogicException::class);

        try {
            self::getContainer()->get(InscriptionLinkService::class)->send($licencie);
        } finally {
            self::assertCount(0, $this->messagesEnvoyes());
        }
    }

    /* ── Outils ── */

    /**
     * Le transport est null:// en test : rien ne part, mais le MessageLoggerListener
     * de framework.test conserve les messages, ce qui les rend assertables.
     *
     * @return \Symfony\Component\Mime\Email[]
     */
    private function messagesEnvoyes(): array
    {
        return array_map(self::asEmail(...), self::getMailerMessages());
    }

    private static function asEmail(\Symfony\Component\Mime\RawMessage $message): \Symfony\Component\Mime\Email
    {
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);

        return $message;
    }

    private function diagEmail(): string
    {
        return self::getContainer()->get(BetaModeService::class)->getRedirectEmail();
    }

    private function seedLicencie(
        ?string $email = 'kevin.martin@example.test',
        string $code = 'SENIOR',
        LicenceStatus $statut = LicenceStatus::LINK_SENT,
    ): Licencie {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode($code)->setLabel($code)->setIsEcoleFoot($code !== 'SENIOR');

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus($statut);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        // Le côté inverse Licencie->dossierClub n'a pas de setter : il faut relire
        // l'entité pour que la relation soit peuplée, comme en conditions réelles.
        $uuid = $licencie->getUuid();
        $em->clear();

        return $em->find(Licencie::class, $uuid);
    }
}

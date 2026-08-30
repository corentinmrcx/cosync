<?php declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Repository\EnvoiMailRepository;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * La passe quotidienne de relance.
 *
 * C'est la seule commande du projet qui écrit à des licenciés sans qu'aucun admin ne soit
 * devant l'écran. Deux verrous en découlent, et ce sont eux qu'on teste ici : elle ne fait
 * rien tant que l'interrupteur est éteint, et `--dry-run` n'envoie jamais rien.
 */
final class RelancesEnvoyerCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private Season $season;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLicencieARelancer();
    }

    /**
     * Le verrou principal. Un déploiement installe le robot, il ne l'allume pas : sans ce
     * test, une régression ferait écrire à tout un effectif à la première mise en prod.
     */
    public function testInterrupteurEteintAucunMailNePart(): void
    {
        $this->reglerRelances(active: false);

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('désactivée', $tester->getDisplay());
        self::assertCount(0, self::getMailerMessages());
    }

    public function testInterrupteurAllumeLaRelancePart(): void
    {
        $this->reglerRelances(active: true);

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, self::getMailerMessages());
        self::assertStringContainsString('1 relance(s) envoyée(s)', $tester->getDisplay());
    }

    /** L'envoi laisse sa trace : c'est elle qui repoussera la relance suivante de dix jours. */
    public function testLaRelanceEnvoyeeEstJournaliseeCommeAutomatique(): void
    {
        $this->reglerRelances(active: true);

        $this->lancer();

        $envois = self::getContainer()->get(EnvoiMailRepository::class)->findBy(['type' => TypeMail::RELANCE_DOSSIER]);

        self::assertCount(1, $envois);
        self::assertSame(OrigineEnvoi::AUTOMATIQUE, $envois[0]->getOrigine());
    }

    /**
     * Le mail de paiement rend un gabarit différent de celui du dossier — et le seul que
     * la passe automatique ne rend jamais sur un effectif où personne n'a rempli. Il porte
     * le montant, les instructions du mode déclaré et un bouton qui dit ce qu'il fait :
     * payer en ligne, pas « régler sa cotisation » — qui règle par chèque n'a rien à y faire.
     */
    public function testLaRelanceDePaiementPorteLeMontantEtLeBoutonDePaiementEnLigne(): void
    {
        $this->reglerRelances(active: true);
        $this->passerLeDossierEnComplete();

        $this->lancer();

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);

        $corps = (string) $messages[0]->getHtmlBody();
        self::assertStringContainsString('85 €', $corps);
        self::assertStringContainsString('Payer en ligne', $corps);
        self::assertStringNotContainsString('Compléter mon dossier', $corps);
    }

    /**
     * `--dry-run` sert à regarder la liste avant d'allumer : il court-circuite donc
     * l'interrupteur, mais jamais l'envoi.
     */
    public function testDryRunAfficheLaListeSansRienEnvoyer(): void
    {
        $this->reglerRelances(active: true);

        $tester = $this->lancer(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('MARTIN Kevin', $tester->getDisplay());
        self::assertStringContainsString('Aucun mail envoyé', $tester->getDisplay());
        self::assertCount(0, self::getMailerMessages());
    }

    public function testDryRunResteUtilisableRobotEteint(): void
    {
        $this->reglerRelances(active: false);

        $tester = $this->lancer(['--dry-run' => true]);

        self::assertStringContainsString('MARTIN Kevin', $tester->getDisplay());
        self::assertCount(0, self::getMailerMessages());
    }

    public function testUneSaisonInconnueEchoueSansRienEnvoyer(): void
    {
        $this->reglerRelances(active: true);

        $tester = $this->lancer(['--saison' => '1998-1999']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(0, self::getMailerMessages());
    }

    /* ── Outils ── */

    /** @param array<string, mixed> $options */
    private function lancer(array $options = []): CommandTester
    {
        $commande = (new Application(self::$kernel))->find('app:relances:envoyer');
        $tester = new CommandTester($commande);
        $tester->execute($options + ['--saison' => $this->season->getLabel()]);

        return $tester;
    }

    /** Dossier rempli mais cotisation non enregistrée : l'autre moitié de la population. */
    private function passerLeDossierEnComplete(): void
    {
        $dossier = $this->em->getRepository(DossierClub::class)->findOneBy([]);
        self::assertNotNull($dossier);

        $dossier->setStatus(LicenceStatus::FORM_COMPLETED)->setFormCompletedAt(new \DateTimeImmutable('-35 days'));
        $this->em->flush();
    }

    private function reglerRelances(bool $active): void
    {
        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setRelanceActive($active)->setRelanceDelaiJours(10)->setRelanceMax(3);
        $settings->enregistrer();
    }

    /** Contacté il y a longtemps, dossier jamais rempli : exactement le cas que le cron vise. */
    private function seedLicencieARelancer(): void
    {
        $ancien = new \DateTimeImmutable('-40 days');

        $this->season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $category = (new Category())->setCode('U16')->setLabel('U16')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('2010-01-01'))
            ->setEmail('parent@example.test')
            ->setCategory($category)
            ->setSeason($this->season)
            ->setLinkSentAt($ancien);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::LINK_SENT);

        $envoi = (new EnvoiMail(TypeMail::INSCRIPTION_LINK, OrigineEnvoi::ADMIN, 'parent@example.test'))
            ->rattacherA($licencie)
            ->setSentAt($ancien);

        foreach ([$this->season, $category, $licencie, $dossier, $envoi] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }
}

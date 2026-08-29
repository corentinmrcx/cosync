<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Repository\EnvoiMailRepository;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les écrans de relance, avec des données réelles.
 *
 * Ce que ces tests protègent : l'écran groupé doit rester utilisable **robot éteint** —
 * c'est le seul moyen de relancer avant qu'on l'allume, et le moyen de voir ce qu'il ferait.
 * Et la sélection postée doit être repassée au crible : un uuid ajouté au formulaire ne peut
 * pas faire écrire à quelqu'un que l'écran ne proposait pas.
 */
final class RelanceEcransTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private Season $season;
    private Category $category;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $this->category = (new Category())->setCode('U16')->setLabel('U16')->setIsEcoleFoot(false);

        $this->em->persist($this->season);
        $this->em->persist($this->category);
        $this->em->flush();

        $this->reglerRelances(active: false);
    }

    public function testLEcranGroupeListeLesLicencesDuesRobotEteint(): void
    {
        $client = $this->loginAdmin();
        $this->licencieARelancer();

        $html = $client->request('GET', '/admin/effectif/joueurs/relancer')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARTIN Kevin', $html);
        self::assertStringContainsString('Dossier à compléter', $html);
        self::assertStringContainsString('désactivée', $html);
    }

    public function testLEnvoiGroupeRelanceEtJournaliseCommeAdmin(): void
    {
        $client = $this->loginAdmin();
        $licencie = $this->licencieARelancer();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/relancer');
        $client->submit($crawler->selectButton('Relancer 1 licencié')->form());

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertCount(1, self::getMailerMessages());

        $envois = self::getContainer()->get(EnvoiMailRepository::class)
            ->findBy(['licencie' => $licencie, 'type' => TypeMail::RELANCE_DOSSIER]);

        self::assertCount(1, $envois);
        self::assertSame(OrigineEnvoi::ADMIN, $envois[0]->getOrigine());
    }

    /**
     * Un uuid glissé dans le formulaire ne doit pas faire écrire à quelqu'un que l'écran
     * n'affichait pas — ici, une licence déjà soldée.
     */
    public function testUnUuidNonPropoteNeDeclencheAucunEnvoi(): void
    {
        $client = $this->loginAdmin();
        $this->licencieARelancer();
        $soldee = $this->licencieARelancer(nom: 'PAYEE', statut: LicenceStatus::A_VALIDER_FFF);

        // Posté à la main : le formulaire ne propose pas cet uuid, c'est justement le
        // scénario — quelqu'un qui forge la requête.
        $crawler = $client->request('GET', '/admin/effectif/joueurs/relancer');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/effectif/joueurs/relancer', [
            '_token' => $token,
            'personnes' => [(string) $soldee->getUuid()],
        ]);

        self::assertCount(0, self::getMailerMessages());
    }

    public function testLEcranDeReglagesEnregistreLInterrupteur(): void
    {
        $client = $this->loginAdmin();

        $crawler = $client->request('GET', '/admin/club/relances');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $client->submit($form, [
            'relance_settings[relanceActive]' => '1',
            'relance_settings[relanceDelaiJours]' => '14',
        ]);

        self::assertResponseRedirects('/admin/club/relances');

        $settings = self::getContainer()->get(ClubSettingsService::class)->get();
        self::assertTrue($settings->isRelanceActive());
        self::assertSame(14, $settings->getRelanceDelaiJours());
    }

    /**
     * La relance à l'unité ne passe ni par le délai ni par le plafond : c'est un acte
     * délibéré, et la fiche affiche le dernier contact juste au-dessus du bouton.
     */
    public function testLaRelanceUnitaireIgnoreLeDelai(): void
    {
        $client = $this->loginAdmin();
        $licencie = $this->licencieARelancer(
            statut: LicenceStatus::FORM_COMPLETED,
            formCompletedAt: new \DateTimeImmutable('-1 day'),
            dernierMail: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());
        $client->submit($crawler->selectButton('Relancer le paiement')->form());

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $licencie->getUuid());
        self::assertCount(1, self::getMailerMessages());
    }

    /* ── Outils ── */

    private function reglerRelances(bool $active): void
    {
        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setRelanceActive($active)->setRelanceDelaiJours(10)->setRelanceMax(3);
        $settings->enregistrer();
    }

    private function licencieARelancer(
        string $nom = 'MARTIN',
        LicenceStatus $statut = LicenceStatus::LINK_SENT,
        ?\DateTimeImmutable $formCompletedAt = null,
        ?\DateTimeImmutable $dernierMail = null,
    ): Licencie {
        $dernierMail ??= new \DateTimeImmutable('-40 days');

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('2010-01-01'))
            ->setEmail(strtolower($nom) . '@example.test')
            ->setCategory($this->category)
            ->setSeason($this->season)
            ->setLinkSentAt($dernierMail);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus($statut);

        if ($formCompletedAt !== null) {
            $dossier->setFormCompletedAt($formCompletedAt);
        }

        $envoi = (new EnvoiMail(TypeMail::INSCRIPTION_LINK, OrigineEnvoi::ADMIN, (string) $licencie->getEmail()))
            ->rattacherA($licencie)
            ->setSentAt($dernierMail);

        foreach ([$licencie, $dossier, $envoi] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $licencie;
    }

    private function loginAdmin(): KernelBrowser
    {
        $user = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}

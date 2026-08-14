<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture de la boutique et annonce groupée.
 *
 * Le club lance ses licences, puis sa boutique quelques jours plus tard. Deux choses sont
 * donc protégées ici : rien ne s'annonce avant l'ouverture, et l'annonce ne part jamais
 * deux fois au même licencié.
 */
final class BoutiqueAnnonceTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const URL = 'https://www.helloasso.com/associations/fc-soudron/boutiques/boutique-du-club';

    public function testOuvrirLaBoutiqueNEnvoieAucunMail(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->seedLicencie(inscrit: true);
        $this->configurerBoutique(self::URL, ouverte: false);

        $crawler = $client->request('GET', '/admin/boutique');
        self::assertCount(0, $crawler->filter('.boutique-switch[disabled]'), 'Un lien est prêt : l\'interrupteur est actif');

        $client->request('POST', '/admin/boutique/ouverture', [
            'ouvrir' => '1',
            '_token' => $this->jeton($client, 'boutique_ouverture'),
        ]);

        self::assertResponseRedirects('/admin/boutique');
        self::assertTrue($this->rechargerSettings()->aBoutique());
        self::assertCount(0, self::getMailerMessages(), 'L\'ouverture affiche le lien, elle n\'écrit à personne');
    }

    /**
     * L'interrupteur est désactivé sans lien, mais la garde vit côté serveur : ouvrir sur du
     * vide afficherait un lien mort au premier licencié qui termine son inscription.
     */
    public function testSansLienLaBoutiqueNePeutPasSOuvrir(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->configurerBoutique(null, ouverte: false);

        $crawler = $client->request('GET', '/admin/boutique');
        self::assertCount(1, $crawler->filter('.boutique-switch[disabled]'));

        $client->request('POST', '/admin/boutique/ouverture', [
            'ouvrir' => '1',
            '_token' => $this->jeton($client, 'boutique_ouverture'),
        ]);

        self::assertResponseRedirects('/admin/boutique/lien');
        self::assertFalse($this->rechargerSettings()->aBoutique());
    }

    public function testFermerLaBoutiqueConserveLeLien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->configurerBoutique(self::URL, ouverte: true);

        $client->request('GET', '/admin/boutique');
        $client->request('POST', '/admin/boutique/ouverture', [
            'ouvrir' => '0',
            '_token' => $this->jeton($client, 'boutique_ouverture'),
        ]);

        $settings = $this->rechargerSettings();
        self::assertSame(self::URL, $settings->getBoutiqueUrl());
        self::assertFalse($settings->aBoutique());
    }

    /** Annoncer une boutique fermée écrirait un lien que personne ne peut voir. */
    public function testBoutiqueFermeeLEcranDAnnonceEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->seedLicencie(inscrit: true);
        $this->configurerBoutique(self::URL, ouverte: false);

        $client->request('GET', '/admin/boutique/annoncer');

        self::assertResponseRedirects('/admin/boutique');
    }

    public function testLEcranNeProposeQueLesDossiersCompletes(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $seasonId = $this->seedSeason();
        $this->seedLicencie(inscrit: true, nom: 'INSCRIT', seasonId: $seasonId);
        $this->seedLicencie(inscrit: false, nom: 'ENATTENTE', seasonId: $seasonId);
        $this->configurerBoutique(self::URL, ouverte: true);

        $crawler = $client->request('GET', '/admin/boutique/annoncer');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.envoi-liens-item'));
        self::assertStringContainsString('INSCRIT', $crawler->filter('.envoi-liens-list')->text());
        self::assertStringNotContainsString('ENATTENTE', $crawler->filter('.envoi-liens-list')->text());
    }

    public function testLAnnonceCocheePartEtEstDatee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(inscrit: true);
        $this->configurerBoutique(self::URL, ouverte: true);

        $client->request('GET', '/admin/boutique/annoncer');
        $client->request('POST', '/admin/boutique/annoncer', [
            'licencies' => [$uuid],
            '_token' => $this->jeton($client, 'annoncer_boutique'),
        ]);

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertStringContainsString(self::URL, (string) $messages[0]->getHtmlBody());
        self::assertNotNull($this->rechargerLicencie($uuid)->getBoutiqueAnnonceeAt());
    }

    public function testUnLicencieDecocheNeRecoitRien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(inscrit: true);
        $this->configurerBoutique(self::URL, ouverte: true);

        $client->request('GET', '/admin/boutique/annoncer');
        $client->request('POST', '/admin/boutique/annoncer', [
            'licencies' => [],
            '_token' => $this->jeton($client, 'annoncer_boutique'),
        ]);

        self::assertCount(0, self::getMailerMessages());
        self::assertNull($this->rechargerLicencie($uuid)->getBoutiqueAnnonceeAt());
    }

    /** Le fait daté, et non l'état du dossier, empêche le second envoi. */
    public function testUnLicencieDejaAnnonceNEstPlusPropose(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(inscrit: true);
        $this->configurerBoutique(self::URL, ouverte: true);

        $client->request('GET', '/admin/boutique/annoncer');
        $client->request('POST', '/admin/boutique/annoncer', [
            'licencies' => [$uuid],
            '_token' => $this->jeton($client, 'annoncer_boutique'),
        ]);
        self::assertCount(1, self::getMailerMessages());

        $crawler = $client->request('GET', '/admin/boutique/annoncer');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.envoi-liens-item'));
        self::assertStringContainsString('reçu l\'annonce', $crawler->filter('.envoi-liens-empty')->text());
    }

    /* ── Outils ── */

    /**
     * Le gestionnaire de jetons lit la session, qui n'existe que le temps d'une requête :
     * on lui remet celle du client avant de demander le jeton.
     */
    private function jeton(KernelBrowser $client, string $id): string
    {
        $request = new Request();
        $request->setSession($client->getRequest()->getSession());
        self::getContainer()->get(RequestStack::class)->push($request);

        return (string) self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function configurerBoutique(?string $url, bool $ouverte): void
    {
        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setBoutiqueUrl($url)->setBoutiqueOuverte($ouverte);
        $settings->enregistrer();
    }

    private function rechargerSettings(): \App\Entity\ClubSettings
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(ClubSettingsService::class)->get();
    }

    private function rechargerLicencie(string $uuid): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Licencie::class, Uuid::fromString($uuid));
    }

    private function seedSeason(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $em->persist($season);
        $em->flush();

        return $season->getId();
    }

    /**
     * La saison est passée par son id, jamais par l'objet : chaque seed termine par un
     * `clear()`, qui détacherait une instance gardée d'un appel à l'autre.
     */
    private function seedLicencie(bool $inscrit, string $nom = 'MARTIN', ?int $seasonId = null): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = $em->find(Season::class, $seasonId ?? $this->seedSeason());
        // Le code de catégorie est unique : le second licencié réutilise celle du premier.
        $category = $em->getRepository(Category::class)->findOneBy(['code' => 'SENIOR'])
            ?? (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail(strtolower($nom) . '@example.test')
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($inscrit ? LicenceStatus::FORM_COMPLETED : LicenceStatus::LINK_SENT)
            ->setFormCompletedAt($inscrit ? new \DateTimeImmutable() : null);

        foreach ([$category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-boutique-annonce@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }
}

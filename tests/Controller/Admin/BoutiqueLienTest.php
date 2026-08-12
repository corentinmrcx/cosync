<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ClubSettings;
use App\Entity\Season;
use App\Entity\User;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Section « Boutique » du tableau de bord et son unique réglage : le lien de la boutique.
 *
 * Le lien est rendu tel quel dans un href, sur la page de confirmation publique et dans
 * un mail : l'écran doit refuser ce qui n'est pas une adresse web.
 */
final class BoutiqueLienTest extends WebTestCase
{
    private const URL = 'https://www.helloasso.com/associations/fc-soudron/boutiques/boutique-du-club';

    public function testLeTableauDeBordMeneALaBoutique(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/boutique"]'));
    }

    public function testLaSectionBoutiqueMeneAuReglageDuLien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/boutique');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/boutique/lien"]'));
    }

    public function testLeLienSaisiEstEnregistrePourLeClub(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/boutique/lien');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="boutique_settings"]')->form();
        $form['boutique_settings[boutiqueUrl]'] = self::URL;
        $client->submit($form);

        self::assertSame(self::URL, $this->rechargerReglages()->getBoutiqueUrl());
        self::assertTrue($this->rechargerReglages()->aBoutique());
    }

    /** Un champ vidé doit redevenir null, sans quoi aBoutique() annoncerait une boutique vide. */
    public function testViderLeLienRetireLaBoutique(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->configurer(self::URL);

        $crawler = $client->request('GET', '/admin/boutique/lien');
        $form = $crawler->filter('form[name="boutique_settings"]')->form();
        $form['boutique_settings[boutiqueUrl]'] = '';
        $client->submit($form);

        $settings = $this->rechargerReglages();
        self::assertNull($settings->getBoutiqueUrl());
        self::assertFalse($settings->aBoutique());
    }

    public function testUneAdresseInvalideEstRefusee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/boutique/lien');
        $form = $crawler->filter('form[name="boutique_settings"]')->form();
        $form['boutique_settings[boutiqueUrl]'] = 'javascript:alert(1)';
        $client->submit($form);

        self::assertNull($this->rechargerReglages()->getBoutiqueUrl(), 'Une adresse non http(s) ne doit pas être enregistrée');
    }

    /** L'écran est de niveau club : il reste utilisable sans aucune saison. */
    public function testAccessibleSansSaison(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-boutique-sans-saison@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/boutique/lien');

        self::assertResponseIsSuccessful();
    }

    /* ── Outils ── */

    private function configurer(?string $url): void
    {
        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setBoutiqueUrl($url);
        $settings->enregistrer();
    }

    private function rechargerReglages(): ClubSettings
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(ClubSettingsService::class)->get();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-boutique@example.test')->setPassword('x');
        $user->setSelectedSeason($season);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Coordonnées bancaires du club, saisies depuis /admin/club/coordonnees-bancaires.
 *
 * Elles ont d'abord été portées par la saison, ce qui obligeait à les re-saisir à chaque
 * rentrée et les affichait dans les réglages de la saison alors qu'elles appartiennent à
 * l'association. Elles vivent désormais dans ClubSettings, hors de toute saison.
 */
final class ClubCoordonneesBancairesTest extends WebTestCase
{
    public function testLEcranProposeLesTroisChampsBancaires(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/coordonnees-bancaires');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="club_settings[iban]"]'));
        self::assertCount(1, $crawler->filter('input[name="club_settings[bic]"]'));
        self::assertCount(1, $crawler->filter('input[name="club_settings[titulaireCompte]"]'));
    }

    public function testLaSaisieEstEnregistreePourLeClub(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/coordonnees-bancaires');
        $form = $crawler->filter('form[name="club_settings"]')->form();

        $form['club_settings[iban]'] = 'FR76 3000 4000 0300 0000 0000 143';
        $form['club_settings[bic]'] = 'BNPAFRPPXXX';
        $form['club_settings[titulaireCompte]'] = 'Association Test';

        $client->submit($form);

        $settings = $this->rechargerReglages();
        self::assertSame('FR76 3000 4000 0300 0000 0000 143', $settings->getIban());
        self::assertSame('BNPAFRPPXXX', $settings->getBic());
        self::assertSame('Association Test', $settings->getTitulaireCompte());
        self::assertTrue($settings->accepteVirement());
    }

    /**
     * Un champ vidé arrive en chaîne vide ; le stocker tel quel laisserait accepteVirement()
     * répondre vrai et le formulaire public proposerait un virement sans IBAN.
     */
    public function testViderLIbanDesactiveLeVirement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        self::getContainer()->get(ClubSettingsService::class)->get()->setIban('FR76 3000 4000 0300 0000 0000 143');
        self::getContainer()->get(ClubSettingsService::class)->enregistrer();

        $crawler = $client->request('GET', '/admin/club/coordonnees-bancaires');
        $form = $crawler->filter('form[name="club_settings"]')->form();
        $form['club_settings[iban]'] = '';
        $client->submit($form);

        $settings = $this->rechargerReglages();
        self::assertNull($settings->getIban(), 'Un champ vidé doit devenir null, pas une chaîne vide');
        self::assertFalse($settings->accepteVirement());
    }

    /** L'écran est de niveau club : il reste utilisable sans aucune saison. */
    public function testAccessibleSansSaison(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-rib@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/club/coordonnees-bancaires');

        self::assertResponseIsSuccessful();
    }

    /* ── Outils ── */

    private function rechargerReglages(): \App\Entity\ClubSettings
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(ClubSettingsService::class)->get();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-rib@example.test')->setPassword('x');
        $user->setSelectedSeason($season);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

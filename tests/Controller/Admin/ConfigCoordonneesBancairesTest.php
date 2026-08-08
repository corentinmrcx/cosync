<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Saisie des coordonnées bancaires du club depuis /admin/config.
 *
 * Elles étaient codées en dur dans les templates : les porter par la saison n'a d'intérêt
 * que si le bureau peut réellement les modifier sans redéploiement.
 */
final class ConfigCoordonneesBancairesTest extends WebTestCase
{
    public function testLEcranProposeLesTroisChampsBancaires(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/config');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="season[iban]"]'));
        self::assertCount(1, $crawler->filter('input[name="season[bic]"]'));
        self::assertCount(1, $crawler->filter('input[name="season[titulaireCompte]"]'));
    }

    public function testLaSaisieEstEnregistreeSurLaSaison(): void
    {
        $client = static::createClient();
        $season = $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/config');
        $form = $crawler->filter('form[name="season"]')->form();

        $form['season[iban]'] = 'FR76 3000 4000 0300 0000 0000 143';
        $form['season[bic]'] = 'BNPAFRPPXXX';
        $form['season[titulaireCompte]'] = 'Association Test';

        $client->submit($form);

        $rechargee = $this->recharger($season);
        self::assertSame('FR76 3000 4000 0300 0000 0000 143', $rechargee->getIban());
        self::assertSame('BNPAFRPPXXX', $rechargee->getBic());
        self::assertSame('Association Test', $rechargee->getTitulaireCompte());
        self::assertTrue($rechargee->accepteVirement());
    }

    /**
     * Un champ vidé arrive en chaîne vide ; le stocker tel quel laisserait accepteVirement()
     * répondre vrai et le formulaire public proposer un virement sans IBAN.
     */
    public function testViderLIbanDesactiveLeVirement(): void
    {
        $client = static::createClient();
        $season = $this->loginAdmin($client, iban: 'FR76 3000 4000 0300 0000 0000 143');

        $crawler = $client->request('GET', '/admin/config');
        $form = $crawler->filter('form[name="season"]')->form();

        $form['season[iban]'] = '';
        $client->submit($form);

        $rechargee = $this->recharger($season);
        self::assertNull($rechargee->getIban(), 'Un champ vidé doit devenir null, pas une chaîne vide');
        self::assertFalse($rechargee->accepteVirement());
    }

    /* ── Outils ── */

    private function recharger(Season $season): Season
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = $season->getId();
        $em->clear();

        return $em->find(Season::class, $id);
    }

    private function loginAdmin(KernelBrowser $client, ?string $iban = null): Season
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85)->setIban($iban);
        $user = (new User())->setEmail('admin-config@example.test')->setPassword('x');
        $user->setSelectedSeason($season);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $season;
    }
}

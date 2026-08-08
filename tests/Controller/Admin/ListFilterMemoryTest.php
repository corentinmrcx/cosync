<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test fonctionnel : les filtres des listes admin sont mémorisés en session et
 * restaurés quand on revient sur la liste sans paramètres.
 */
final class ListFilterMemoryTest extends WebTestCase
{
    public function testLeFiltreEstRestaureApresUneVisiteSansParametres(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        // 1. Visite avec un filtre → il est mémorisé.
        $client->request('GET', '/admin/licencies?status=validated');
        self::assertResponseIsSuccessful();

        // 2. Retour sur la liste nue → redirection vers l'URL filtrée.
        $client->request('GET', '/admin/licencies');
        self::assertResponseRedirects();
        self::assertStringContainsString('status=validated', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testPasDeRedirectionSansFiltreMemorise(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/licencies');
        self::assertResponseIsSuccessful();
    }

    public function testReinitialiserOublieLeFiltreMemorise(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        // Mémorise un filtre…
        $client->request('GET', '/admin/licencies?status=validated');
        // …puis réinitialise (URL avec seulement search vide, sans statut).
        $client->request('GET', '/admin/licencies?search=');
        self::assertResponseIsSuccessful();

        // Retour nu : plus rien à restaurer.
        $client->request('GET', '/admin/licencies');
        self::assertResponseIsSuccessful();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-filtres@example.com')->setPassword('x');

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

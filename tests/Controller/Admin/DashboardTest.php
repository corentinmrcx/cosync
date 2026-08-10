<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Racine de l'administration : les portes de ce qui ne dépend d'aucune saison — les saisons,
 * le stock physique et le club — plus un raccourci vers la saison de travail, le trajet quotidien.
 */
final class DashboardTest extends WebTestCase
{
    public function testLesPortesHorsSaisonEtUnRaccourciVersLaSaisonDeTravail(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();

        $labels = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Saisons', 'Stock', 'Clés', 'Le club'], $labels);

        $raccourci = $crawler->filter('.dashboard-season a.quicklink');
        self::assertCount(1, $raccourci);
        self::assertStringContainsString('2025-2026', $raccourci->text());
        self::assertSame('/admin/saison', $raccourci->attr('href'));
    }

    public function testSansSaisonLeRaccourciCedeLaPlaceAUneInvitation(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-dashboard@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.dashboard-season'));
        self::assertStringContainsString('Aucune saison configurée', (string) $client->getResponse()->getContent());
    }

    public function testLeNiveauClubRegroupeLesReglagesHorsSaison(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club');

        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Coordonnées bancaires', 'Catégories FFF', 'Utilisateurs'], $labels);
    }

    /* ── Outils ── */

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-dashboard@example.test')->setPassword('x');
        $user->setSelectedSeason($season);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }
}

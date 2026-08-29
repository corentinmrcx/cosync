<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['Saisons', 'Stock', 'Clés', 'Boutique', 'Le club'], $labels);

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
        // Les grilles de tailles ne sont pas une entrée de plus : elles traduisent le
        // référentiel des tailles et se rejoignent depuis son écran.
        self::assertSame(
            ['Identité de l\'association', 'Coordonnées bancaires', 'Relances automatiques', 'Catégories FFF', 'Tailles', 'Utilisateurs'],
            $labels,
        );
    }

    /**
     * Une carte dont l'icône est inconnue de `hub-card.html.twig` rend un cadre vide, sans
     * la moindre erreur : le composant porte son propre jeu d'icônes, distinct de
     * `_icon.html.twig`, et un nom pris dans le mauvais des deux passe inaperçu jusqu'à ce
     * que quelqu'un ouvre la page. C'est arrivé en ajoutant « Relances automatiques ».
     */
    #[DataProvider('pagesAvecDesCartes')]
    public function testChaqueCarteDUnHubPorteSonIcone(string $url): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $cartes = $crawler->filter('.hub-card');
        self::assertGreaterThan(0, $cartes->count(), 'La page doit présenter au moins une carte.');

        $sansIcone = $cartes->reduce(
            static fn ($carte): bool => $carte->filter('.hub-card-icon svg')->count() === 0,
        )->each(static fn ($carte): string => trim($carte->filter('.hub-card-label')->text()));

        self::assertSame([], $sansIcone, 'Ces cartes n\'ont pas d\'icône : le nom passé est inconnu de hub-card.html.twig.');
    }

    /** @return iterable<string, array{string}> */
    public static function pagesAvecDesCartes(): iterable
    {
        yield 'racine' => ['/admin/'];
        yield 'club' => ['/admin/club'];
        yield 'saison' => ['/admin/saison'];
        yield 'saisons' => ['/admin/saisons'];
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

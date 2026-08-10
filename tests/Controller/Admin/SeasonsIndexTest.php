<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sélection de la saison : une carte par saison, cliquer dessus fait entrer dedans.
 * La gestion (renommer, supprimer) vit derrière, sur un écran distinct.
 */
final class SeasonsIndexTest extends WebTestCase
{
    public function testUneCarteParSaisonPlusLaCreation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons');

        self::assertResponseIsSuccessful();

        $labels = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));
        self::assertContains('2025-2026', $labels);
        self::assertContains('2024-2025', $labels);
        self::assertSame('Nouvelle saison', end($labels), 'La création ferme la liste');

        $badges = $crawler->filter('.hub-card-badge')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['En cours'], $badges, 'Une seule carte porte la mention');

        self::assertCount(0, $crawler->filter('.hub-card-sub'), 'Les cartes de saison ne portent que leur libellé');
    }

    /** L'écran de gestion ne fait plus doublon : il ne sert qu'à modifier et supprimer. */
    public function testLEcranDeGestionNOffrePlusQueModifierEtSupprimer(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons/gerer');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('a:contains("Modifier")'));
        // Les deux saisons sont vides : la saison de travail se supprime comme l'autre.
        self::assertCount(2, $crawler->filter('button:contains("Supprimer")'));
        self::assertCount(0, $crawler->filter('button:contains("Y travailler")'));
        self::assertStringNotContainsString(
            'Nouvelle saison',
            (string) $client->getResponse()->getContent(),
            'La création reste sur l\'écran de sélection',
        );
    }

    /**
     * La cotisation par défaut n'appartient qu'à l'écran Cotisations : la redemander ici
     * laisserait deux endroits où changer le même montant.
     */
    public function testLeFormulaireDeModificationNeProposePasLaCotisation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $saison = $em->getRepository(Season::class)->findOneBy(['label' => '2024-2025']);

        $crawler = $client->request('GET', '/admin/saisons/' . $saison->getId() . '/modifier');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="season[startYear]"]'));
        self::assertCount(0, $crawler->filter('input[name="season[cotisationDefaut]"]'));
        self::assertStringContainsString('Cotisations', (string) $client->getResponse()->getContent());
    }

    /** Le montant reste demandé à la création, pour configurer la saison d'un coup. */
    public function testLeFormulaireDeCreationProposeLaCotisation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons/nouvelle');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="season[cotisationDefaut]"]'));
    }

    public function testCliquerSurUneSaisonYFaitEntrer(): void
    {
        $client = static::createClient();
        $user = $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons');

        $autre = $crawler->filter('.hub-card-form')->reduce(
            static fn ($node) => str_contains($node->text(), '2024-2025'),
        )->first();
        $client->submit($autre->form());

        self::assertResponseRedirects('/admin/saison');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(
            '2024-2025',
            $em->find(User::class, $user->getId())->getSelectedSeason()?->getLabel(),
        );
    }

    /* ── Outils ── */

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $precedente = (new Season())->setLabel('2024-2025')->setCotisationDefaut(80);
        $courante = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-saisons-index@example.test')->setPassword('x');
        $user->setSelectedSeason($courante);

        $em->persist($precedente);
        $em->persist($courante);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }
}

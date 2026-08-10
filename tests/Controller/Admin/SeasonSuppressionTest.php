<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Suppression d'une saison depuis /admin/saisons/gerer.
 *
 * Deux règles : une saison vide se supprime — y compris celle dans laquelle on travaille,
 * sinon une saison créée par erreur devient courante à sa création et reste là pour
 * toujours — et une saison qui contient quoi que ce soit dit pourquoi elle résiste.
 */
final class SeasonSuppressionTest extends WebTestCase
{
    public function testLaSaisonEnCoursEtVideEstSupprimable(): void
    {
        $client = static::createClient();
        [$user, $precedente, $courante] = $this->deuxSaisonsVides($client);

        $crawler = $client->request('GET', '/admin/saisons/gerer');
        self::assertResponseIsSuccessful();

        $client->submit($this->ligne($crawler, $courante->getLabel())->filter('form')->form());

        self::assertResponseRedirects('/admin/saisons/gerer');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertNull(
            self::getContainer()->get(SeasonRepository::class)->findOneBy(['label' => $courante->getLabel()]),
            'La saison de travail, vide, a bien été supprimée',
        );
        self::assertSame(
            $precedente->getLabel(),
            $em->find(User::class, $user->getId())->getSelectedSeason()?->getLabel(),
            'On bascule sur la saison restante au lieu de rester dans une saison supprimée',
        );
    }

    /**
     * Le cas qui rendait une 500 : aucune de ces tables n'a d'ON DELETE, l'ancien contrôle
     * ne comptait que licenciés et dirigeants et laissait passer la suppression.
     */
    public function testUneSaisonAvecUneEquipeMaisAucunLicencieNEstPasSupprimable(): void
    {
        $client = static::createClient();
        [, , $courante] = $this->deuxSaisonsVides($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Team())->setName('U15 A')->setSeason($courante));
        $em->flush();

        $crawler = $client->request('GET', '/admin/saisons/gerer');

        $ligne = $this->ligne($crawler, $courante->getLabel());
        self::assertCount(0, $ligne->filter('button:contains("Supprimer")'));
        self::assertStringContainsString('1 équipe', $ligne->text());
    }

    /** Le template masque le bouton, mais c'est le contrôleur qui doit faire foi. */
    public function testLeControleServeurRefuseMemeAvecUnJetonValide(): void
    {
        $client = static::createClient();
        [, , $courante] = $this->deuxSaisonsVides($client);

        // Page rendue quand la saison était encore vide : le formulaire, jeton compris, est valide.
        $crawler = $client->request('GET', '/admin/saisons/gerer');
        $formulaire = $this->ligne($crawler, $courante->getLabel())->filter('form')->form();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Team())->setName('U15 A')->setSeason($courante));
        $em->flush();

        $client->submit($formulaire);

        self::assertResponseRedirects('/admin/saisons/gerer');
        $em->clear();
        self::assertNotNull(
            self::getContainer()->get(SeasonRepository::class)->findOneBy(['label' => $courante->getLabel()]),
            'La saison contient désormais une équipe : elle survit au POST',
        );
    }

    public function testLaDerniereSaisonNEstPasSupprimable(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $seule = $this->creerSaison('2025-2026');
        $user = (new User())->setEmail('admin-season-suppression-seule@example.test')->setPassword('x');
        $user->setSelectedSeason($seule);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/saisons/gerer');

        $ligne = $this->ligne($crawler, '2025-2026');
        self::assertCount(0, $ligne->filter('button:contains("Supprimer")'));
        self::assertStringContainsString('Dernière saison', $ligne->text());
    }

    public function testLaRaisonDuBlocageEstChiffree(): void
    {
        $client = static::createClient();
        [, $precedente] = $this->deuxSaisonsVides($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $em->persist($category);
        $em->persist((new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($precedente));
        $em->persist((new Dirigeant())->setNom('MARTIN')->setPrenom('Kevin')->setSeason($precedente));
        $em->persist((new Dirigeant())->setNom('DURAND')->setPrenom('Léa')->setSeason($precedente));
        $em->flush();

        $crawler = $client->request('GET', '/admin/saisons/gerer');

        $ligne = $this->ligne($crawler, $precedente->getLabel());
        self::assertCount(0, $ligne->filter('button:contains("Supprimer")'));
        self::assertStringContainsString('1 licencié', $ligne->text());
        self::assertStringContainsString('2 dirigeants', $ligne->text());
    }

    /* ── Outils ── */

    /** La ligne de la liste qui porte ce libellé de saison. */
    private function ligne(Crawler $crawler, string $label): Crawler
    {
        $ligne = $crawler->filter('.season-list-item')
            ->reduce(static fn (Crawler $node) => str_contains($node->text(), $label));

        self::assertCount(1, $ligne, sprintf('La saison "%s" apparaît une fois dans la liste', $label));

        return $ligne;
    }

    /**
     * Deux saisons sans aucune donnée rattachée, la plus récente étant celle de travail.
     *
     * @return array{User, Season, Season} utilisateur, saison précédente, saison courante
     */
    private function deuxSaisonsVides(KernelBrowser $client): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $precedente = $this->creerSaison('2024-2025');
        $courante = $this->creerSaison('2025-2026');

        $user = (new User())->setEmail('admin-season-suppression@example.test')->setPassword('x');
        $user->setSelectedSeason($courante);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return [$user, $precedente, $courante];
    }

    private function creerSaison(string $label): Season
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        $em->persist($season);
        $em->flush();

        return $season;
    }
}

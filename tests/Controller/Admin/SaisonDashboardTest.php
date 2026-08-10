<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Niveau saison : tableau de bord d'entrée, puis les deux écrans de réglage qui ont remplacé
 * l'ancienne page « Paramètres de la saison » — Cotisations (les montants) et Équipes (les
 * équipes et leurs catégories FFF).
 */
final class SaisonDashboardTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLeTableauDeBordDeLaSaisonDonneAccesAuxSections(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saison');

        self::assertResponseIsSuccessful();

        $sections = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Effectif', 'Dotations'], $sections);

        $reglages = $crawler->filter('.quicklink-title')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Cotisations', 'Équipes', 'Documents à signer', 'Stock', 'Clés'], $reglages);
    }

    /**
     * Ni le stock physique ni le trousseau de clés ne portent de saison : ils se
     * gèrent au niveau club. La saison n'en garde que des raccourcis, explicitement
     * rangés hors de ses propres réglages.
     */
    public function testLaSaisonNeGardeDuStockEtDesClesQueDesRaccourcisVersLeNiveauClub(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saison');

        self::assertResponseIsSuccessful();

        $stock = $crawler->filter('a.quicklink[href="/admin/stock"]');
        self::assertCount(1, $stock, 'La saison doit garder un raccourci vers le stock du club.');
        self::assertStringContainsString('toutes les saisons', $stock->text());

        $cles = $crawler->filter('a.quicklink[href="/admin/cles"]');
        self::assertCount(1, $cles, 'La saison doit garder un raccourci vers le registre des clés.');
        self::assertStringContainsString('chaque saison', $cles->text(), 'Le raccourci rappelle que l\'attestation, elle, est annuelle.');
    }

    public function testLeFilDArianePartDuClubPuisDeLaSaison(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saison/cotisations');

        self::assertResponseIsSuccessful();
        $fil = $crawler->filter('.breadcrumb-link, .breadcrumb-current')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Tableau de bord', 'Saison 2025-2026', 'Cotisations'], $fil);
    }

    public function testLaCotisationParDefautEstEnregistree(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saison/cotisations');
        $form = $crawler->filter('form[action="/admin/saison/cotisations/defaut"]')->form();
        $form['cotisation_defaut'] = '120';
        $client->submit($form);

        self::assertResponseRedirects('/admin/saison/cotisations');
        self::assertSame(120, $this->rechargerSaison()->getCotisationDefaut());
    }

    public function testLaCotisationDUneEquipeEstEnregistree(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $team = $this->creerEquipe();

        $crawler = $client->request('GET', '/admin/saison/cotisations');
        $form = $crawler->filter('form[action="/admin/saison/cotisations/equipes"]')->form();
        $form['cotisations[' . $team->getId() . ']'] = '150';
        $client->submit($form);

        self::assertSame(150, $this->rechargerEquipe()->getCotisation());
    }

    /** Champ vidé : l'équipe repasse sur la cotisation par défaut de la saison. */
    public function testViderLaCotisationDUneEquipeLaRemetParDefaut(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $team = $this->creerEquipe(cotisation: 150);

        $crawler = $client->request('GET', '/admin/saison/cotisations');
        $form = $crawler->filter('form[action="/admin/saison/cotisations/equipes"]')->form();
        $form['cotisations[' . $team->getId() . ']'] = '';
        $client->submit($form);

        self::assertNull($this->rechargerEquipe()->getCotisation());
    }

    /**
     * Le nom et les catégories se règlent sur l'écran Équipes : cet enregistrement ne doit
     * pas effacer au passage la cotisation, réglée sur l'autre écran.
     */
    public function testRenommerUneEquipePreserveSaCotisation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $team = $this->creerEquipe(cotisation: 150);

        $crawler = $client->request('GET', '/admin/saison/equipes');
        $form = $crawler->filter('form[action="/admin/saison/equipes/' . $team->getId() . '/modifier"]')->form();
        $form['team[name]'] = 'SENIOR B';
        $client->submit($form);

        $rechargee = $this->rechargerEquipe();
        self::assertSame('SENIOR B', $rechargee->getName());
        self::assertSame(150, $rechargee->getCotisation(), 'La cotisation appartient à l\'autre écran');
    }

    /* ── Outils ── */

    private function creerEquipe(?int $cotisation = null): Team
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = (new Team())->setName('SENIOR')->setSeason($this->season)->setCotisation($cotisation);
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function rechargerSaison(): Season
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = $this->season->getId();
        $em->clear();

        return $em->find(Season::class, $id);
    }

    private function rechargerEquipe(): Team
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(TeamRepository::class)->findOneBy([]);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-saison@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

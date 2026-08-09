<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Cotisations » : les équipes se règlent d'un bloc, en un seul envoi.
 */
final class CotisationsEcranTest extends WebTestCase
{
    private const COTISATION_DEFAUT = 85;

    private Season $courante;
    private Season $precedente;

    public function testEnregistreToutesLesEquipesEnUnSeulEnvoi(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $u15 = $this->creerEquipe('U15 A', $this->courante, 120);
        $seniors = $this->creerEquipe('Séniors 1', $this->courante, 100);

        $client->request('POST', '/admin/saison/cotisations/equipes', [
            '_token' => $this->jeton($client),
            'cotisations' => [
                (string) $u15->getId() => '90',
                (string) $seniors->getId() => '',
            ],
        ]);

        self::assertResponseRedirects('/admin/saison/cotisations');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(90, $em->getRepository(Team::class)->find($u15->getId())->getCotisation());
        self::assertNull(
            $em->getRepository(Team::class)->find($seniors->getId())->getCotisation(),
            'Un champ vidé fait retomber l\'équipe sur la cotisation par défaut',
        );

        $crawler = $client->followRedirect();
        self::assertStringContainsString('Cotisations par équipe mises à jour', $crawler->filter('.flash-success')->text());
    }

    /** Une saisie fautive ne doit pas laisser la moitié de la liste enregistrée. */
    public function testUnMontantNegatifNeModifieAucuneEquipe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $u15 = $this->creerEquipe('U15 A', $this->courante, 120);
        $seniors = $this->creerEquipe('Séniors 1', $this->courante, 100);

        $client->request('POST', '/admin/saison/cotisations/equipes', [
            '_token' => $this->jeton($client),
            'cotisations' => [
                (string) $u15->getId() => '90',
                (string) $seniors->getId() => '-10',
            ],
        ]);

        self::assertResponseRedirects('/admin/saison/cotisations');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(120, $em->getRepository(Team::class)->find($u15->getId())->getCotisation());
        self::assertSame(100, $em->getRepository(Team::class)->find($seniors->getId())->getCotisation());

        $crawler = $client->followRedirect();
        self::assertStringContainsString('Séniors 1', $crawler->filter('.flash-error')->text());
    }

    /** Un id d'équipe étranger à la saison de travail est ignoré, sans erreur. */
    public function testUneEquipeDUneAutreSaisonEstIgnoree(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $ancienne = $this->creerEquipe('U15 A', $this->precedente, 70);
        $this->creerEquipe('U15 A', $this->courante, 120);

        $client->request('POST', '/admin/saison/cotisations/equipes', [
            '_token' => $this->jeton($client),
            'cotisations' => [(string) $ancienne->getId() => '999'],
        ]);

        self::assertResponseRedirects('/admin/saison/cotisations');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(70, $em->getRepository(Team::class)->find($ancienne->getId())->getCotisation());
    }

    /**
     * Le champ vide annonce le montant qui s'appliquera, seul : « 85 (défaut) » ne tenait pas
     * dans la largeur du champ et se lisait tronqué.
     */
    public function testLeChampVideAfficheLaCotisationParDefautEnPlaceholder(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->creerEquipe('U15 A', $this->courante, null);

        $crawler = $client->request('GET', '/admin/saison/cotisations');

        self::assertResponseIsSuccessful();

        $champ = $crawler->filter('.cotisations-team-input');
        self::assertCount(1, $champ);
        self::assertSame((string) self::COTISATION_DEFAUT, $champ->attr('placeholder'));
        self::assertSame('', $champ->attr('value'));

        self::assertCount(
            1,
            $crawler->filter('.cotisations-equipes button[type=submit]'),
            'Un seul bouton pour toute la liste',
        );
    }

    private function creerEquipe(string $nom, Season $season, ?int $cotisation): Team
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = (new Team())->setName($nom)->setSeason($season)->setCotisation($cotisation);
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function jeton(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/saison/cotisations');

        return $crawler->filter('.cotisations-equipes input[name=_token]')->attr('value');
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->precedente = (new Season())->setLabel('2024-2025')->setCotisationDefaut(80);
        $this->courante = (new Season())->setLabel('2025-2026')->setCotisationDefaut(self::COTISATION_DEFAUT);
        $user = (new User())->setEmail('admin-cotisations@example.test')->setPassword('x');
        $user->setSelectedSeason($this->courante);

        $em->persist($this->precedente);
        $em->persist($this->courante);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }
}

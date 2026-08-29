<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rattrapage de l'affectation automatique depuis l'écran « Équipes ».
 *
 * Le scénario visé : un effectif importé avant que les équipes n'existent, donc entièrement
 * sans équipe. Le bouton doit rattraper ces licenciés-là, et seulement ceux-là.
 */
final class AffectationEquipeAutoTest extends WebTestCase
{
    private Season $courante;
    private Season $precedente;

    public function testAffecteLesLicenciesSansEquipeSansToucherAuxAutres(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $u15 = $this->creerCategorie('U15');
        $seniors = $this->creerCategorie('SENIOR');

        $equipeU15 = $this->creerEquipe('U15 A', $this->courante, [$u15]);
        $this->creerEquipe('Séniors 1', $this->courante, [$seniors]);
        $equipeLoisirs = $this->creerEquipe('Loisirs', $this->courante, []);

        $sansEquipe = $this->creerLicencie('DUPONT', $u15, $this->courante, null);
        $dejaAffecte = $this->creerLicencie('MARTIN', $u15, $this->courante, $equipeLoisirs);

        $client->request('POST', '/admin/saison/equipes/affectation-automatique', [
            '_token' => $this->jeton($client),
        ]);

        self::assertResponseRedirects('/admin/saison/equipes');

        self::assertSame($equipeU15->getId(), $this->equipeDe($sansEquipe)?->getId());
        self::assertSame(
            $equipeLoisirs->getId(),
            $this->equipeDe($dejaAffecte)?->getId(),
            'Une équipe déjà renseignée n\'est jamais remplacée',
        );

        $crawler = $client->followRedirect();
        self::assertStringContainsString('U15 A (1)', $crawler->filter('.flash-success')->text());
    }

    /** Deux équipes sur la même catégorie : aucune règle ne tranche, on ne devine pas. */
    public function testUneCategoriePartageeParDeuxEquipesNAffecteRien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $u15 = $this->creerCategorie('U15');
        $seniors = $this->creerCategorie('SENIOR');

        $this->creerEquipe('U15 A', $this->courante, [$u15]);
        $this->creerEquipe('U15 B', $this->courante, [$u15]);
        $equipeSeniors = $this->creerEquipe('Séniors 1', $this->courante, [$seniors]);

        $ambigu = $this->creerLicencie('DUPONT', $u15, $this->courante, null);
        $tranchable = $this->creerLicencie('MARTIN', $seniors, $this->courante, null);

        $crawler = $client->request('GET', '/admin/saison/equipes');
        self::assertStringContainsString('U15 : 1', $crawler->filter('.config-affectation-reste')->text());
        self::assertCount(1, $crawler->filter('.config-affectation-liste li'), 'Seule l\'équipe Séniors est proposée');

        $client->request('POST', '/admin/saison/equipes/affectation-automatique', [
            '_token' => $crawler->filter('.config-affectation-actions input[name=_token]')->attr('value'),
        ]);

        self::assertResponseRedirects('/admin/saison/equipes');

        self::assertNull($this->equipeDe($ambigu));
        self::assertSame($equipeSeniors->getId(), $this->equipeDe($tranchable)?->getId());
    }

    /** Chaque admin travaille dans sa saison : l'effectif d'une autre saison n'est pas touché. */
    public function testUnLicencieDUneAutreSaisonNEstPasAffecte(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $u15 = $this->creerCategorie('U15');
        $equipeCourante = $this->creerEquipe('U15 A', $this->courante, [$u15]);
        $this->creerEquipe('U15 A', $this->precedente, [$u15]);

        $ancien = $this->creerLicencie('DUPONT', $u15, $this->precedente, null);
        $courant = $this->creerLicencie('MARTIN', $u15, $this->courante, null);

        $client->request('POST', '/admin/saison/equipes/affectation-automatique', [
            '_token' => $this->jeton($client),
        ]);

        self::assertResponseRedirects('/admin/saison/equipes');

        self::assertNull($this->equipeDe($ancien));
        self::assertSame($equipeCourante->getId(), $this->equipeDe($courant)?->getId());
    }

    /** Relit le licencié depuis une unité de travail vierge : on vérifie la base, pas l'objet en mémoire. */
    private function equipeDe(Licencie $licencie): ?Team
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Licencie::class)->findOneBy(['uuid' => $licencie->getUuid()])?->getTeam();
    }

    /** @param Category[] $categories */
    private function creerEquipe(string $nom, Season $season, array $categories): Team
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = (new Team())->setName($nom)->setSeason($season);
        foreach ($categories as $category) {
            $team->addCategory($category);
        }

        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function creerCategorie(string $code): Category
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $category = (new Category())->setCode($code)->setLabel($code)->setIsEcoleFoot(false);
        $em->persist($category);
        $em->flush();

        return $category;
    }

    private function creerLicencie(string $nom, Category $category, Season $season, ?Team $team): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2010-05-03'))
            ->setCategory($category)
            ->setSeason($season);
        $licencie->setTeam($team);

        $em->persist($licencie);
        $em->flush();

        return $licencie;
    }

    private function jeton(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/saison/equipes');

        return $crawler->filter('.config-affectation-actions input[name=_token]')->attr('value');
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->precedente = (new Season())->setLabel('2024-2025')->setCotisationDefaut(80);
        $this->courante = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-affectation@example.test')->setPassword('x');
        $user->setSelectedSeason($this->courante);

        $em->persist($this->precedente);
        $em->persist($this->courante);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\RoleAcces;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\Permission;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le mode édition des listes d'effectif : la sortie de secours d'un import mal filtré.
 *
 * Deux choses s'y jouent, et les deux sont vérifiées ici : que le geste reste hors de portée
 * des autres comptes admin — c'est justement le profil qui rate un import —, et qu'une fiche
 * ayant une histoire dans le club survive à la sélection la plus large.
 */
final class ModeEditionEffectifTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Season $season;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testLeBoutonNEstOffertQuAuxComptesQuiPeuventSupprimer(): void
    {
        $this->loginSansSuppression();
        $ordinaire = $this->client->request('GET', '/admin/effectif/joueurs')->html();

        self::assertStringNotContainsString('Mode édition', $ordinaire);

        $this->login(); // super-admin : passe-partout
        $diagnostic = $this->client->request('GET', '/admin/effectif/joueurs')->html();

        self::assertStringContainsString('Mode édition', $diagnostic);
    }

    public function testUnCompteSansLeDroitNePeutPasAtteindreLaRouteDeSuppression(): void
    {
        $this->loginSansSuppression();
        $licencie = $this->makeLicencie('FANTOME');

        // Le jeton n'a pas d'importance : le refus tombe avant le corps du contrôleur.
        $this->client->request('POST', '/admin/effectif/joueurs/supprimer', [
            '_token' => 'peu-importe',
            'licencies' => [(string) $licencie->getUuid()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testLaBasculeAfficheUneCaseParLigne(): void
    {
        $this->login();
        $this->makeLicencie('FANTOME');

        $crawler = $this->client->request('GET', '/admin/effectif/joueurs?edition=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="licencies[]"]'));
    }

    /**
     * Le mode édition n'est pas un filtre — il n'est donc pas mémorisé — mais il ne doit pas se
     * perdre dans la redirection qui restaure les filtres de la session.
     */
    public function testLaBasculeSurvitALaRestaurationDesFiltres(): void
    {
        $this->login();
        $this->client->request('GET', '/admin/effectif/joueurs?search=fantome');

        $this->client->request('GET', '/admin/effectif/joueurs?edition=1');

        self::assertResponseRedirects();
        self::assertStringContainsString('edition=1', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('search=fantome', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUneFicheViergeEstAnnonceePuisSupprimee(): void
    {
        $this->login();
        $licencie = $this->makeLicencie('FANTOME');
        $uuid = (string) $licencie->getUuid();

        $crawler = $this->confirmation('licencies', [$uuid]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('FANTOME', $crawler->html());
        self::assertCount(1, $crawler->filter('input[name="licencies[]"][value="' . $uuid . '"]'));

        $this->client->submitForm('Supprimer définitivement', ['confirmation' => '1']);

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertNull($this->licencies()->findByUuid($licencie->getUuid()));
    }

    /**
     * Le garde-fou central : une fiche touchée est annoncée comme épargnée, son uuid n'est même
     * pas repris dans le formulaire de confirmation, et elle survit à la validation.
     */
    public function testUneFicheAvecUneHistoireEstEpargneeMalgreLaSelection(): void
    {
        $this->login();
        $vierge = $this->makeLicencie('FANTOME');
        $touche = $this->makeLicencie('VRAI');
        $touche->setLinkSentAt(new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $crawler = $this->confirmation('licencies', [(string) $vierge->getUuid(), (string) $touche->getUuid()]);

        self::assertStringContainsString('1 fiche épargnée', $crawler->html());
        self::assertStringContainsString('12/08/2026', $crawler->html());
        self::assertCount(
            0,
            $crawler->filter('input[name="licencies[]"][value="' . $touche->getUuid() . '"]'),
            'Le formulaire de confirmation ne reprend que les fiches supprimables.',
        );

        $this->client->submitForm('Supprimer définitivement', ['confirmation' => '1']);

        self::assertNull($this->licencies()->findByUuid($vierge->getUuid()));
        self::assertNotNull($this->licencies()->findByUuid($touche->getUuid()));
    }

    /**
     * Le service rejoue l'analyse : un uuid protégé glissé à la main dans la requête finale ne
     * doit pas passer, même en contournant l'écran de confirmation.
     */
    public function testUnUuidProtegeForceDansLaRequeteFinaleNePassePas(): void
    {
        $this->login();
        $vierge = $this->makeLicencie('FANTOME');
        $touche = $this->makeLicencie('VRAI');
        $touche->setLinkSentAt(new \DateTimeImmutable());
        $this->em->flush();

        // Écran de confirmation légitime, dont on détourne le jeton pour y glisser l'uuid protégé.
        $crawler = $this->confirmation('licencies', [(string) $vierge->getUuid()]);
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/effectif/joueurs/supprimer/confirmer', [
            '_token' => $token,
            'licencies' => [(string) $touche->getUuid()],
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertNotNull($this->licencies()->findByUuid($touche->getUuid()));
    }

    public function testLeMemeGesteExistePourLesDirigeants(): void
    {
        $this->login();
        $dirigeant = (new Dirigeant())->setNom('FANTOME')->setPrenom('Luc')->setSeason($this->season);
        $this->em->persist($dirigeant);
        $this->em->flush();

        $crawler = $this->confirmation('dirigeants', [(string) $dirigeant->getUuid()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('FANTOME', $crawler->html());

        $this->client->submitForm('Supprimer définitivement', ['confirmation' => '1']);

        self::assertResponseRedirects('/admin/effectif/dirigeants');
        self::assertNull(self::getContainer()->get(DirigeantRepository::class)->findByUuid($dirigeant->getUuid()));
    }

    /* ── Outils ── */

    private function licencies(): LicencieRepository
    {
        $this->em->clear();

        return self::getContainer()->get(LicencieRepository::class);
    }

    /**
     * Le jeton est relu sur la page qui le rend, jamais fabriqué : c'est la liste en mode
     * édition qui le porte, et le lire ici vérifie du même coup qu'elle le rend bien.
     */
    private function tokenDeLaListe(string $url): string
    {
        return (string) $this->client->request('GET', $url)
            ->filter('input[name="_token"]')
            ->attr('value');
    }

    /**
     * @param list<string> $uuids
     */
    private function confirmation(string $population, array $uuids): \Symfony\Component\DomCrawler\Crawler
    {
        $liste = '/admin/effectif/' . ($population === 'licencies' ? 'joueurs' : 'dirigeants');

        return $this->client->request('POST', $liste . '/supprimer', [
            '_token' => $this->tokenDeLaListe($liste . '?edition=1'),
            $population => $uuids,
        ]);
    }

    private function login(string $email = 'admin@example.test'): void
    {
        $this->connecter($email, superAdmin: true);
    }

    /**
     * Un compte qui consulte l'effectif sans pouvoir en supprimer une fiche — le profil le
     * plus courant, et justement celui qui rate un import.
     */
    private function loginSansSuppression(string $email = 'autre-admin@example.test'): void
    {
        $role = (new RoleAcces())
            ->setNom('Lecture effectif ' . $email)
            ->setPermissions([Permission::EFFECTIF_LIRE, Permission::EFFECTIF_GERER]);

        $this->em->persist($role);

        $this->connecter($email, superAdmin: false, roles: [$role]);
    }

    /** @param list<RoleAcces> $roles */
    private function connecter(string $email, bool $superAdmin, array $roles = []): void
    {
        $user = (new User())->setSuperAdmin($superAdmin)->setEmail($email)->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        foreach ($roles as $role) {
            $user->ajouterRoleAcces($role);
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function makeLicencie(string $nom): Licencie
    {
        static $n = 0;
        ++$n;

        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas' . $n)
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($this->season);
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::IMPORTED);
        $this->em->persist($dossier);
        $licencie->setDossierClub($dossier);

        $this->em->flush();

        return $licencie;
    }
}

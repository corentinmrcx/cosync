<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\RoleAcces;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que les rôles ferment réellement.
 *
 * Jusqu'à cette epic, tout compte connecté pouvait tout faire : la seule règle était
 * `^/ → ROLE_USER`. Ces tests vérifient l'autre moitié du dispositif — non pas que le voter
 * calcule juste ({@see \App\Tests\Service\Compte\PermissionCollectorTest}), mais que les
 * écrans le consultent vraiment.
 *
 * Le garde-fou complémentaire est `bin/check-permissions.php`, qui refuse une action
 * d'administration sans droit déclaré. Les deux sont nécessaires : le contrôle CI dit qu'un
 * droit est écrit, ces tests disent qu'il est appliqué.
 */
final class PermissionsAccesTest extends WebTestCase
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

    /**
     * Le refus par défaut, vu du compte : il se connecte, il navigue, il ne voit rien.
     * C'est le comportement voulu — un compte mal réglé se plaint, il ne fuite pas.
     */
    public function testUnCompteSansRoleEntreMaisNAccedeARien(): void
    {
        $this->connecter([]);

        $this->client->request('GET', '/admin/');
        self::assertResponseIsSuccessful('Le tableau de bord est un point de navigation.');

        $this->client->request('GET', '/admin/effectif/joueurs');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/stock/gestion');
        self::assertResponseStatusCodeSame(403);
    }

    /** Le cas qui a motivé l'epic : la trésorière voit l'effectif et les paiements, rien d'autre. */
    public function testLaTresorerieVoitLEffectifMaisPasLeStockNiLImport(): void
    {
        $this->connecter([
            Permission::PAIEMENT_ENCAISSER,
            Permission::PAIEMENT_ATTESTER,
            Permission::LICENCE_VALIDER_FFF,
        ]);

        $this->client->request('GET', '/admin/effectif/joueurs');
        self::assertResponseIsSuccessful('effectif.lire est accordé par implication de paiement.encaisser.');

        $this->client->request('GET', '/admin/stock/gestion');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/effectif/import');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/dotations/suivi');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le piège du dispositif : une lecture accordée ne doit jamais laisser passer l'écriture
     * du même domaine. C'est l'inverse de l'implication, et c'est là que l'oubli coûte cher.
     */
    public function testUneLectureSeuleNOuvrePasLesEcransDEcriture(): void
    {
        $this->connecter([Permission::EFFECTIF_LIRE]);

        $this->client->request('GET', '/admin/effectif/joueurs');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/effectif/joueurs/envoyer-liens');
        self::assertResponseStatusCodeSame(403, 'Envoyer les liens relève de effectif.gerer.');

        $this->client->request('GET', '/admin/effectif/joueurs/nouveau');
        self::assertResponseStatusCodeSame(403);
    }

    /** Consulter le stock n'autorise pas à le reconfigurer, ni à saisir un mouvement. */
    public function testConsulterLeStockNOuvrePasSaConfiguration(): void
    {
        $this->connecter([Permission::STOCK_LIRE]);

        $this->client->request('GET', '/admin/stock/gestion');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/stock/items/nouveau');
        self::assertResponseStatusCodeSame(403);
    }

    /** Les comptes et les rôles sont derrière leur propre droit, absent des rôles livrés. */
    public function testLesComptesEtLesRolesRestentFermes(): void
    {
        $this->connecter([Permission::CLUB_IDENTITE]);

        $this->client->request('GET', '/admin/club/identite');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/club/utilisateurs');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/club/roles');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Les cinq écrans du club sont indépendants.
     *
     * Ils vivaient sous un cran unique : donner le RIB à la trésorerie lui donnait aussi le
     * signataire des attestations et les référentiels sportifs. Ce test tient le découpage —
     * c'est le genre de chose qu'un `toutSauf()` malheureux recollerait sans qu'on le voie.
     */
    public function testLeRibNOuvrePasLIdentiteNiLesReferentiels(): void
    {
        $this->connecter([Permission::CLUB_RIB]);

        $this->client->request('GET', '/admin/club/coordonnees-bancaires');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/club/identite');
        self::assertResponseStatusCodeSame(403, 'Le signataire des attestations engage l\'association.');

        $this->client->request('GET', '/admin/club/categories-fff');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/club/tailles');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/club/relances');
        self::assertResponseStatusCodeSame(403);
    }

    /** Et l'inverse : les référentiels sportifs n'ouvrent ni le RIB ni l'identité. */
    public function testLesReferentielsNOuvrentPasLesReglagesDeLAssociation(): void
    {
        $this->connecter([Permission::CLUB_REFERENTIELS]);

        $this->client->request('GET', '/admin/club/categories-fff');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/club/tailles');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/club/coordonnees-bancaires');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/club/identite');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le hub « Le club » ne montre que les réglages ouverts, et son en-tête disparaît avec
     * eux : un titre « Réglages du club » suivi du vide se lit comme une panne.
     */
    public function testLeHubDuClubNAfficheQueLesReglagesOuverts(): void
    {
        $this->connecter([Permission::CLUB_RIB]);

        $crawler = $this->client->request('GET', '/admin/club');
        $cartes = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));

        self::assertSame(['Coordonnées bancaires'], $cartes);
        self::assertStringNotContainsString('Accès à l\'application', $crawler->html());
    }

    /** Aucun droit dans le domaine : la porte d'entrée elle-même s'efface. */
    public function testSansAucunDroitDeClubLaPorteDuHubDisparait(): void
    {
        $this->connecter([Permission::EFFECTIF_LIRE]);

        $html = $this->client->request('GET', '/admin/')->html();

        self::assertStringNotContainsString('admin/club', $html);
    }

    /**
     * On masque ce qu'on ne possède pas : sans ça, la présidente clique sur six cartes pour
     * obtenir six pages d'erreur.
     */
    public function testLeTableauDeBordNAfficheQueLesPortesOuvertes(): void
    {
        $this->connecter([Permission::EFFECTIF_LIRE]);

        $crawler = $this->client->request('GET', '/admin/');
        $cartes = $crawler->filter('.hub-card-label')->each(static fn ($n) => trim($n->text()));

        self::assertContains('Saisons', $cartes, 'La bascule de saison reste un point de navigation.');
        self::assertNotContains('Stock', $cartes);
        self::assertNotContains('Clés', $cartes);
        self::assertNotContains('Boutique', $cartes);
        self::assertNotContains('Le club', $cartes);
    }

    /**
     * Le pendant du refus : l'écran ne doit pas proposer un geste qui répondra 403. Le
     * contrôleur refuse déjà — c'est l'affichage qui, sans ça, mène l'admin dans le mur.
     */
    public function testUneLectureSeuleNeVoitPasLesBoutonsDEcriture(): void
    {
        $this->connecter([Permission::EFFECTIF_LIRE]);

        $html = $this->client->request('GET', '/admin/effectif/joueurs')->html();

        self::assertStringNotContainsString('Mode édition', $html);
        self::assertStringNotContainsString('licencies-add-btn', $html, 'Créer une fiche relève de effectif.gerer.');
    }

    /**
     * Le même contrôle, mais sur un écran dont **tous** les gestes sont des écritures.
     *
     * La gestion du stock en alignait neuf : « Nouvel article », « Modifier », « Supprimer »,
     * « + / − Mouvement », les notes… Un rôle de consultation les voyait tous, et les neuf
     * répondaient « Access Denied ». C'est le cas qui a motivé `peut_acceder()`.
     */
    public function testLaConsultationDuStockNeVoitAucunBoutonDEcriture(): void
    {
        $this->connecter([Permission::STOCK_LIRE]);

        $html = $this->client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Nouvel article', $html);
        self::assertStringNotContainsString('+ / − Mouvement', $html, 'La saisie d\'un mouvement relève de stock.gerer.');
        self::assertStringNotContainsString('Ajouter une note', $html, 'Les notes de stock aussi.');
        self::assertStringNotContainsString('Supprimer', $html);
    }

    /**
     * Le pendant indispensable : un droit accordé doit **rendre** le bouton. Une garde qui
     * masque tout passerait les tests ci-dessus sans rien servir à personne.
     */
    public function testLaGestionDuStockRetrouveSesBoutons(): void
    {
        $this->connecter([Permission::STOCK_CONFIGURER, Permission::STOCK_GERER]);

        $html = $this->client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nouvel article', $html);
    }

    /**
     * Le planning distingue deux gestes que rien ne sépare visuellement : consulter les matchs
     * et en ajouter. Le formulaire de saisie poste sur une route `planning.gerer` dont l'URL
     * est posée par le contrôleur — la garde du template est la seule qui puisse le cacher.
     */
    public function testLaConsultationDuPlanningNeVoitNiSaisieNiGeneration(): void
    {
        $this->connecter([Permission::PLANNING_LIRE]);

        $html = $this->client->request('GET', '/admin/outils/planning-matchs')->html();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ajouter un match', $html);
        self::assertStringNotContainsString('Générer un planning', $html);
        self::assertStringNotContainsString('Import par collage', $html);
    }

    /**
     * Les cases à cocher des rôles sortent du thème de formulaire, une paire par conteneur.
     * Sans ce conteneur, Symfony les aligne sans rien entre elles : « Responsable foot☐
     * Trésorerie », collé et illisible — c'est ce qu'affichait l'écran avant correction.
     */
    public function testLesCasesDesRolesSontSepareesParLeThemeDeFormulaire(): void
    {
        $this->connecter([], superAdmin: true);

        $crawler = $this->client->request('GET', '/admin/club/utilisateurs/nouveau');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.field-choices'), 'La liste porte le conteneur du socle.');
        self::assertSame(
            $crawler->filter('.field-choices input[type="checkbox"]')->count(),
            $crawler->filter('.field-choices .field-choice')->count(),
            'Chaque case a son conteneur : sans lui, les libellés se collent.',
        );
    }

    /** Les exceptions déclarées `#[AccesLibre]` doivent rester ouvertes, sinon plus rien ne s'atteint. */
    public function testLesPointsDeNavigationRestentOuverts(): void
    {
        $this->connecter([]);

        foreach (['/admin/', '/admin/saison', '/admin/saisons', '/admin/profil', '/admin/documentation'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url . ' doit rester accessible à tout compte connecté.');
        }
    }

    /** Le passe-partout, vérifié de bout en bout : c'est lui qui empêche de se verrouiller dehors. */
    public function testLeSuperAdminPassePartout(): void
    {
        $this->connecter([], superAdmin: true);

        foreach (['/admin/effectif/joueurs', '/admin/stock/gestion', '/admin/club/roles', '/admin/diagnostic'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /** @param list<Permission> $permissions */
    private function connecter(array $permissions, bool $superAdmin = false): void
    {
        static $n = 0;
        ++$n;

        $user = (new User())
            ->setEmail(sprintf('perm%d@example.test', $n))
            ->setSuperAdmin($superAdmin);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        if ($permissions !== []) {
            $role = (new RoleAcces())
                ->setNom(sprintf('Rôle de test %d', $n))
                ->setPermissions($permissions);

            $this->em->persist($role);
            $user->ajouterRoleAcces($role);
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }
}

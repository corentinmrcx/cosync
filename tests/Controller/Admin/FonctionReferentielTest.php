<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Dirigeant;
use App\Entity\Fonction;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DirigeantRole;
use App\Service\Referentiel\FonctionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le référentiel des fonctions du club, et son arrivée sur la fiche d'un dirigeant.
 *
 * Une fonction dit ce que la personne fait — c'est elle qui sort sur les documents remis à
 * un tiers. Le rôle, lui, dit ce que l'application lui doit ; les deux cohabitent sur la
 * fiche sans se confondre.
 */
final class FonctionReferentielTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Season $season;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testLeHubDuClubMeneAuxFonctions(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/club');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/club/fonctions"]'));
    }

    public function testUneFonctionSeCreeDepuisLEcran(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', '/admin/club/fonctions/nouvelle', [
            '_token' => $this->jeton('form[action="/admin/club/fonctions/nouvelle"]'),
            'libelle' => 'Coordinateur général',
            'porte_equipe' => '0',
        ]);

        self::assertResponseRedirects('/admin/club/fonctions');
        self::assertNotNull($this->fonction('Coordinateur général'));
    }

    public function testDeuxFonctionsNePeuventPasPorterLeMemeLibelle(): void
    {
        $this->loginAdmin();
        $this->makeFonction('Coordinateur général');

        $this->client->request('POST', '/admin/club/fonctions/nouvelle', [
            '_token' => $this->jeton('form[action="/admin/club/fonctions/nouvelle"]'),
            'libelle' => 'Coordinateur général',
        ]);

        self::assertCount(
            1,
            $this->em->getRepository(Fonction::class)->findBy(['libelle' => 'Coordinateur général']),
        );
    }

    /**
     * Supprimer une fonction portée par quelqu'un la retirerait de sa fiche et du
     * récapitulatif remis à la mairie, sans que rien ne le dise. L'écran ne propose donc
     * pas le geste, et le service le refuse — masquer n'est pas protéger.
     */
    public function testUneFonctionPorteeParUnDirigeantNeSeSupprimePas(): void
    {
        $this->loginAdmin();
        $fonction = $this->makeFonction('Coordinateur général');
        $dirigeant = $this->makeDirigeant('MARCOUX', 'Corentin');
        $dirigeant->addFonction($fonction);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/club/fonctions');

        self::assertCount(
            0,
            $crawler->filter('form[action="/admin/club/fonctions/' . $fonction->getId() . '/supprimer"]'),
            'L\'écran ne doit pas offrir un geste que le service refusera.',
        );
        self::assertCount(1, $crawler->filter('.fonction-btn-disabled'), 'Le bouton reste, pour dire pourquoi c\'est refusé.');

        $this->expectException(\DomainException::class);
        self::getContainer()->get(FonctionService::class)->supprimer($fonction);
    }

    public function testUneFonctionQuePersonneNePorteSeSupprime(): void
    {
        $this->loginAdmin();
        $fonction = $this->makeFonction('Fonction obsolète');

        $this->client->request('POST', '/admin/club/fonctions/' . $fonction->getId() . '/supprimer', [
            '_token' => $this->jeton('form[action="/admin/club/fonctions/' . $fonction->getId() . '/supprimer"]'),
        ]);

        self::assertNull($this->fonction('Fonction obsolète'));
    }

    /** Aller-retour complet : ce qui est coché sur la fiche se retrouve en base et à l'écran. */
    public function testLesFonctionsCocheesSurLaFicheSEnregistrent(): void
    {
        $this->loginAdmin();
        $dirigeant = $this->makeDirigeant('LAGRANGE', 'Marlène');
        $ecole = $this->makeFonction('En charge de l\'école de foot', position: 1);
        $organisation = $this->makeFonction('Contribue à l\'organisation du foot', position: 2);

        $this->soumettreFiche($dirigeant, [$ecole, $organisation]);

        $this->em->clear();
        $relu = $this->em->getRepository(Dirigeant::class)->find($dirigeant->getUuid());
        self::assertSame(
            ['En charge de l\'école de foot', 'Contribue à l\'organisation du foot'],
            array_map(static fn (Fonction $f): string => $f->getLibelle(), $relu->getFonctions()->toArray()),
        );

        $crawler = $this->client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid());
        self::assertStringContainsString(
            'En charge de l\'école de foot · Contribue à l\'organisation du foot',
            $crawler->filter('.licencie-show-grid')->text(),
        );
    }

    /** Décocher doit retirer aussi sûrement que cocher ajoute. */
    public function testUneFonctionDecocheeQuitteLaFiche(): void
    {
        $this->loginAdmin();
        $dirigeant = $this->makeDirigeant('LAGRANGE', 'Marlène');
        $dirigeant->addFonction($this->makeFonction('En charge de l\'école de foot'));
        $this->em->flush();

        $this->soumettreFiche($dirigeant, []);

        $this->em->clear();
        $relu = $this->em->getRepository(Dirigeant::class)->find($dirigeant->getUuid());
        self::assertCount(0, $relu->getFonctions());
    }

    /**
     * Le sélecteur reçoit une **liste** JSON, jamais un objet.
     *
     * Les `vars.choices` d'un formulaire sont indexés par libellé : `|map` dessus produisait
     * `{"Coordinateur général": {...}}`, sur quoi `.filter()` n'existe pas. Le composant
     * Alpine mourait sans un mot et le champ restait vide, placeholder compris.
     */
    public function testLeSelecteurDeFonctionsRecoitUneListeEtNonUnObjet(): void
    {
        $this->loginAdmin();
        $this->makeFonction('Coordinateur général');
        $dirigeant = $this->makeDirigeant('MARCOUX', 'Corentin');

        $crawler = $this->client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid() . '/modifier');
        $xData = (string) $crawler->filter('[x-data^="multiSelect"]')->last()->attr('x-data');

        $config = json_decode(substr($xData, (int) strpos($xData, '(') + 1, -1), true);

        self::assertIsArray($config);
        self::assertTrue(array_is_list($config['options']), 'Un objet JSON ici et le composant meurt en silence.');
        self::assertSame('Coordinateur général', $config['options'][0]['label']);
    }

    /** L'équipe entre dans le libellé à l'affichage, elle n'y est jamais recopiée. */
    public function testUneFonctionMarqueeAfficheLEquipeDeLaFiche(): void
    {
        $this->loginAdmin();
        $dirigeant = $this->makeDirigeant('GRIFFON', 'Charley');
        $dirigeant->setTeam($this->makeTeam('U16'));
        $dirigeant->addFonction($this->makeFonction('Entraîneur', porteEquipe: true));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid());

        self::assertStringContainsString('Entraîneur U16', $crawler->filter('.licencie-show-grid')->text());
    }

    /* ── Outils ── */

    /** @param Fonction[] $fonctions */
    private function soumettreFiche(Dirigeant $dirigeant, array $fonctions): void
    {
        $crawler = $this->client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid() . '/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();

        // Le rôle comme les fonctions sont posés par des composants Alpine : le crawler ne
        // voit que des champs vides, on rejoue donc ce que le navigateur enverrait.
        $valeurs = $form->getPhpValues();
        $valeurs['dirigeant']['role'] = $dirigeant->getRole()->value;
        $valeurs['dirigeant']['fonctions'] = array_map(
            static fn (Fonction $f): string => (string) $f->getId(),
            $fonctions,
        );

        $this->client->request('POST', $form->getUri(), $valeurs);
    }

    private function jeton(string $selecteur): string
    {
        $champ = $this->client->request('GET', '/admin/club/fonctions')->filter($selecteur . ' input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), 'Formulaire introuvable : ' . $selecteur);

        return (string) $champ->first()->attr('value');
    }

    private function fonction(string $libelle): ?Fonction
    {
        return $this->em->getRepository(Fonction::class)->findOneBy(['libelle' => $libelle]);
    }

    private function makeFonction(string $libelle, bool $porteEquipe = false, int $position = 0): Fonction
    {
        $fonction = (new Fonction())
            ->setLibelle($libelle)
            ->setPorteEquipe($porteEquipe)
            ->setPosition($position);

        $this->em->persist($fonction);
        $this->em->flush();

        return $fonction;
    }

    private function makeTeam(string $nom): Team
    {
        $team = (new Team())->setName($nom)->setSeason($this->season);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function makeDirigeant(string $nom, string $prenom): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setRole(DirigeantRole::DIRIGEANT)
            ->setSeason($this->season);

        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function loginAdmin(): void
    {
        $this->season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-fonctions@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }
}

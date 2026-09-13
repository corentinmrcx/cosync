<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\GrilleTaille;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Taille;
use App\Entity\User;
use App\Enum\StockItemVetementType;
use App\Enum\TailleType;
use App\Repository\GrilleTailleRepository;
use App\Repository\TailleRepository;
use App\Service\Referentiel\GrilleTailleService;
use App\Service\Referentiel\TailleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les grilles de tailles, réglées par le club.
 *
 * Une grille ne vaut que si sa traduction est déterministe et si ses deux côtés existent au
 * référentiel : ce sont ces deux règles que l'écran doit faire tenir.
 */
final class GrillesTaillesEcranTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testLAccueilDesTaillesMeneAuReferentielEtAuxGrilles(): void
    {
        $client = $this->loginAdmin();

        $crawler = $client->request('GET', '/admin/club/tailles');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.quicklink[href="/admin/club/tailles/referentiel"]'));
        self::assertCount(
            1,
            $crawler->filter('a.quicklink[href="/admin/club/grilles-tailles"]'),
            'Les grilles traduisent ce référentiel : elles s\'atteignent d\'ici, pas par une carte de plus sur le hub du club.',
        );
        self::assertStringNotContainsString(
            'Nouvelle taille',
            $crawler->html(),
            'Ajouter une taille est l\'action de la page qui les liste, pas de l\'accueil de la section.',
        );
    }

    public function testUneGrilleSeCreeEtSOuvreLaOuOnLaRemplit(): void
    {
        $client = $this->loginAdmin();

        $client->request('POST', '/admin/club/grilles-tailles/nouvelle', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/grilles-tailles/nouvelle"]'),
            'nom' => 'Chaussettes Nike',
            'type' => 'pointure',
        ]);

        $grille = $this->grille('Chaussettes Nike');
        self::assertNotNull($grille);
        self::assertSame(TailleType::POINTURE, $grille->getType());
        self::assertResponseRedirects('/admin/club/grilles-tailles/' . $grille->getId(), null, 'Une grille vide ne sert à rien : on ouvre son écran de remplissage.');
    }

    public function testUneLigneTraduitPlusieursPointuresVersUneSeulePlage(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);

        $this->ajouterLigne($client, $grille, '43-46', ['43', '44']);

        $valeurs = $this->em->getRepository(GrilleTaille::class)->find($grille->getId())->getValeurs();
        self::assertCount(1, $valeurs);
        self::assertSame('43-46', $valeurs->first()->getCible()->getLibelle());
        self::assertSame(['43', '44'], $valeurs->first()->libellesCouverts());
    }

    public function testUneTailleDejaCouverteAilleursEstRefusee(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);

        $this->ajouterLigne($client, $grille, '43-46', ['43', '44']);
        $this->ajouterLigne($client, $grille, '39-42', ['44']);

        $html = $client->followRedirect()->html();

        self::assertStringContainsString('déjà couverte par', $html, 'Deux plages pour une même pointure rendraient la traduction indécidable.');
        self::assertCount(1, $this->em->getRepository(GrilleTaille::class)->find($grille->getId())->getValeurs());
    }

    /**
     * Ne rien traduire est le cas normal — un fournisseur ne relabellise souvent qu'une partie
     * de sa gamme. L'écran le dit sans en faire une alerte : c'est une information, sans quoi
     * on croit devoir écrire « L couvre L » pour tout le référentiel.
     */
    public function testLEcranDitCeQuiPasseSansTraduction(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);
        $this->ajouterLigne($client, $grille, '43-46', ['43', '44']);
        // Grille en service : c'est bien ce qui passe sans traduction qu'on regarde ici, pas
        // une grille branchée nulle part — celle-là a son alerte, et pour une autre raison.
        $this->rattacher($grille);

        $crawler = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Servies telles quelles', $crawler->html());
        self::assertCount(1, $crawler->filter('.grille-info'), 'Registre de l\'information, pas de l\'alerte.');
        self::assertCount(0, $crawler->filter('.grille-alerte'));
    }

    public function testUneGrilleVideNeSignaleRien(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);

        $html = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId())->html();

        self::assertStringNotContainsString(
            'Servies telles quelles',
            $html,
            'Sur une grille vide, tout passe tel quel par construction : lister l\'échelle entière serait du bruit.',
        );
    }

    /**
     * Trois grilles parfaitement remplies, rattachées à aucun article, ont traversé une saison
     * sans rien traduire : le club a commandé 80 articles au lieu de 55. L'information était
     * pourtant à l'écran — en gris, dans la même phrase que « Pointure · 6 lignes ». C'est
     * l'habillage qui a failli, pas le calcul.
     */
    public function testUneGrilleRattacheeAAucunArticleSeVoitCommeUneAnomalie(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Erima', TailleType::POINTURE);
        $this->ajouterLigne($client, $grille, '43-46', ['43', '44']);

        $liste = $client->request('GET', '/admin/club/grilles-tailles');

        self::assertCount(1, $liste->filter('.grille-badge-orpheline'));
        self::assertStringContainsString(
            'elle ne traduit rien',
            $liste->filter('.grille-badge-orpheline')->text(),
            'Le fait brut ne suffit pas : c\'est la conséquence qui n\'a pas été comprise.',
        );

        $fiche = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId());

        self::assertCount(
            1,
            $fiche->filter('.grille-alerte'),
            'L\'écran où l\'on remplit la grille est celui où l\'oubli passait inaperçu.',
        );
        self::assertStringContainsString(
            'Grille de tailles',
            $fiche->filter('.grille-alerte')->text(),
            'La marche à suivre, pas seulement le constat : le rattachement se fait depuis l\'article.',
        );
    }

    public function testUneGrilleEnServiceDitCeQuElleTraduit(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Erima', TailleType::POINTURE);

        $this->rattacher($grille);

        $fiche = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId());

        self::assertCount(0, $fiche->filter('.grille-alerte'));
        self::assertStringContainsString(
            'Chaussettes · Erima · Noir',
            $fiche->filter('.grille-usage')->text(),
            'Plusieurs articles portent le même nom : la désignation complète est la règle (§5).',
        );
    }

    public function testUneGrilleEnServiceNeSeSupprimePas(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);

        $article = (new StockItem())->setNom('Chaussettes')->setTypeVetement(StockItemVetementType::CHAUSSURES);
        $article->setGrilleTaille($grille);
        $this->em->persist($article);
        $this->em->flush();

        // L'écran ne propose plus la suppression…
        $crawler = $client->request('GET', '/admin/club/grilles-tailles');
        self::assertCount(0, $crawler->filter('form[action="/admin/club/grilles-tailles/' . $grille->getId() . '/supprimer"]'));
        self::assertStringContainsString('grille-btn-disabled', $crawler->html());

        // … et le service refuse quand même, pour la requête qui contournerait l'écran.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/s\'en sert/');
        self::getContainer()->get(GrilleTailleService::class)->supprimer($grille);
    }

    public function testUneTailleEmployeeParUneGrilleNeSeSupprimePlus(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);
        $this->ajouterLigne($client, $grille, '43-46', ['43']);

        $employes = self::getContainer()->get(TailleService::class)->libellesEmployes();

        self::assertArrayHasKey('43-46', $employes, 'La cible d\'une traduction est employée.');
        self::assertArrayHasKey('43', $employes, 'Une taille couverte l\'est aussi.');
    }

    /** @param list<string> $couvertes */
    private function ajouterLigne(KernelBrowser $client, GrilleTaille $grille, string $cible, array $couvertes): void
    {
        $url = '/admin/club/grilles-tailles/' . $grille->getId();

        $client->request('POST', $url . '/valeurs', [
            '_token' => $this->jetonDe($client, $url, 'form[action="' . $url . '/valeurs"]'),
            'cible' => (string) $this->taille($cible, TailleType::POINTURE, proposee: false)->getId(),
            'couvertures' => array_map(
                fn (string $libelle): string => (string) $this->taille($libelle, TailleType::POINTURE)->getId(),
                $couvertes,
            ),
        ]);
    }

    /** Article du stock qui porte la grille : c'est ce rattachement qui la fait traduire. */
    private function rattacher(GrilleTaille $grille): StockItem
    {
        $article = (new StockItem())
            ->setNom('Chaussettes')
            ->setMarque('Erima')
            ->setCouleur('Noir')
            ->setTypeVetement(StockItemVetementType::CHAUSSURES);
        // Une requête HTTP a pu réinitialiser le gestionnaire : on rattache la grille telle
        // qu'il la connaît maintenant, pas l'instance créée avant l'appel.
        $article->setGrilleTaille($this->em->getReference(GrilleTaille::class, $grille->getId()));

        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    private function creerGrille(string $nom, TailleType $type): GrilleTaille
    {
        $grille = (new GrilleTaille())->setNom($nom)->setType($type);
        $this->em->persist($grille);
        $this->em->flush();

        return $grille;
    }

    /** Taille du référentiel, créée si le seed ne la contient pas (« 43-46 » est propre à un fournisseur). */
    private function taille(string $libelle, TailleType $type = TailleType::POINTURE, bool $proposee = true): Taille
    {
        $existante = self::getContainer()->get(TailleRepository::class)->findOneByLibelle($type, $libelle);
        if ($existante !== null) {
            return $existante;
        }

        $taille = (new Taille())->setLibelle($libelle)->setType($type)->setProposeeAuxLicencies($proposee);
        $this->em->persist($taille);
        $this->em->flush();

        return $taille;
    }

    private function grille(string $nom): ?GrilleTaille
    {
        return self::getContainer()->get(GrilleTailleRepository::class)->findOneBy(['nom' => $nom]);
    }

    private function jeton(KernelBrowser $client, string $selecteur): string
    {
        return $this->jetonDe($client, '/admin/club/grilles-tailles', $selecteur);
    }

    private function jetonDe(KernelBrowser $client, string $url, string $selecteur): string
    {
        $champ = $client->request('GET', $url)->filter($selecteur . ' input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), 'Formulaire introuvable : ' . $selecteur);

        return (string) $champ->first()->attr('value');
    }

    private function loginAdmin(): KernelBrowser
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-grilles@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($season);

        $this->em->persist($season);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}

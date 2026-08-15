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

    public function testLEcranSignaleLesTaillesQueLaGrilleNeTraduitPasEncore(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);
        $this->ajouterLigne($client, $grille, '43-46', ['43', '44']);

        $html = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId())->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Non traduites pour l\'instant', $html);
        self::assertStringContainsString('à renseigner', $html, 'L\'écran doit dire ce que le trou produira dans le suivi.');
    }

    public function testUneGrilleVideNeSignaleRien(): void
    {
        $client = $this->loginAdmin();
        $grille = $this->creerGrille('Chaussettes Nike', TailleType::POINTURE);

        $html = $client->request('GET', '/admin/club/grilles-tailles/' . $grille->getId())->html();

        self::assertStringNotContainsString(
            'Non traduites pour l\'instant',
            $html,
            'Sur une grille vide, tout est non traduit par construction : lister l\'échelle entière serait du bruit.',
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
        $user = (new User())->setEmail('admin-grilles@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($season);

        $this->em->persist($season);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}

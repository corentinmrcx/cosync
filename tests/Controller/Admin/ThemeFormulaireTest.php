<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Le style des champs vient d'une seule classe, .field-input, portée par
 * components/form-field.css. Les templates la posent à la main sur leurs champs écrits
 * en HTML, mais form_widget() rend un <input> nu : c'est templates/form/theme.html.twig
 * qui la lui ajoute. Sans ce thème, tous les formulaires construits avec un Form Type
 * retombent silencieusement sur le style natif du navigateur — la page reste
 * fonctionnelle, rien ne casse, et personne ne le voit avant de l'avoir à l'écran.
 */
final class ThemeFormulaireTest extends WebTestCase
{
    /**
     * Pages dont le formulaire est intégralement rendu par un Form Type : chaque champ
     * doit y porter le socle.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesToutSymfony(): iterable
    {
        foreach ([
            '/admin/saisons/nouvelle',
            '/admin/club/utilisateurs/nouveau',
            '/admin/club/coordonnees-bancaires',
            '/admin/profil',
        ] as $url) {
            yield $url => [$url];
        }
    }

    /**
     * Pages mélangeant widgets Symfony et champs écrits à la main (combobox, multi-select) :
     * un champ y est correct s'il porte une classe, quelle qu'elle soit.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesMixtes(): iterable
    {
        foreach ([
            '/admin/effectif/joueurs/nouveau',
            '/admin/effectif/dirigeants/nouveau',
            '/admin/club/categories-fff',
            '/admin/stock/items/nouveau',
        ] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('pagesToutSymfony')]
    public function testLesChampsRendusParSymfoniePortentLeSocleVisuel(string $url): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $champs = $this->champs($crawler);
        self::assertNotEmpty($champs, "Aucun champ à contrôler sur $url");

        foreach ($champs as $nom => $classes) {
            self::assertContains(
                'field-input',
                explode(' ', $classes),
                sprintf('Le champ "%s" de %s est rendu sans .field-input', $nom, $url),
            );
        }
    }

    #[DataProvider('pagesMixtes')]
    public function testAucunChampNEstRenduSansClasse(string $url): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $champs = $this->champs($crawler);
        self::assertNotEmpty($champs, "Aucun champ à contrôler sur $url");

        foreach ($champs as $nom => $classes) {
            self::assertNotSame(
                '',
                $classes,
                sprintf('Le champ "%s" de %s est rendu sans classe, donc sans style', $nom, $url),
            );
        }

        self::assertGreaterThan(
            0,
            $crawler->filter('form .field-input')->count(),
            "Aucun champ au socle .field-input sur $url",
        );
    }

    public function testLeLibelleEtLAideSuiventLeMemeSocle(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons/nouvelle');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('form label.field-label')->count());
        self::assertGreaterThan(0, $crawler->filter('form .field-hint')->count());
    }

    /* ── Outils ── */

    /**
     * Les champs visibles de la page, indexés par nom : les champs cachés n'ont rien à
     * afficher, cases et boutons radio ont leur propre socle.
     *
     * @return array<string, string> nom du champ => liste de ses classes
     */
    private function champs(Crawler $crawler): array
    {
        $selecteur = 'form input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), form select, form textarea';

        $champs = [];
        foreach ($crawler->filter($selecteur) as $index => $noeud) {
            $champ = new Crawler($noeud);
            $champs[$champ->attr('name') ?? "sans-nom-$index"] = trim($champ->attr('class') ?? '');
        }

        return $champs;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $user = (new User())->setEmail('admin-theme-form@example.test')->setPassword('x');
        $user->setSelectedSeason($season);
        $user->setRoles(['ROLE_SUPER_ADMIN']);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

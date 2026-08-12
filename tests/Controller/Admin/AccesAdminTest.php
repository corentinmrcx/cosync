<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Contrôle d'accès de l'espace admin.
 *
 * La protection ne repose sur aucun attribut #[IsGranted] : tout tient à la règle
 * attrape-tout `- { path: ^/, roles: ROLE_USER }` de security.yaml, précédée d'une
 * liste de préfixes publics. Un préfixe ajouté par erreur à cette liste ouvrirait
 * silencieusement une partie de l'admin — d'où ces tests, qui balayent les deux sens.
 */
final class AccesAdminTest extends WebTestCase
{
    /**
     * Toutes les pages admin sans paramètre d'URL. La liste est explicite plutôt que
     * déduite du routeur : une nouvelle route doit être ajoutée ici sciemment.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesAdmin(): iterable
    {
        $urls = [
            '/admin/',
            '/admin/effectif',
            '/admin/effectif/joueurs',
            '/admin/effectif/joueurs/nouveau',
            '/admin/effectif/joueurs/envoyer-liens',
            '/admin/effectif/dirigeants',
            '/admin/effectif/dirigeants/nouveau',
            '/admin/effectif/import',
            '/admin/saison',
            '/admin/saison/cotisations',
            '/admin/saison/equipes',
            '/admin/club',
            '/admin/club/categories-fff',
            '/admin/club/coordonnees-bancaires',
            '/admin/club/utilisateurs',
            '/admin/club/utilisateurs/nouveau',
            '/admin/saisons',
            '/admin/saisons/gerer',
            '/admin/saisons/nouvelle',
            '/admin/effectif/documents',
            '/admin/effectif/documents/nouveau',
            '/admin/profil',
            '/admin/documentation',
            '/admin/diagnostic',
            '/admin/cles',
            '/admin/cles/attestation',
            '/admin/cles/attestation/apercu',
            '/admin/cles/attestation/recapitulatif',
            '/admin/stock',
            '/admin/stock/items/nouveau',
            '/admin/stock/gestion',
            '/admin/stock/mouvements',
            '/admin/stock/categories',
            '/admin/stock/fournisseurs',
            '/admin/commandes',
            '/admin/dotations',
            '/admin/dotations/modeles',
            '/admin/dotations/suivi',
            '/admin/dotations/flocage',
            '/admin/stock/inventaire.pdf',
        ];

        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('pagesAdmin')]
    public function testUnePageAdminRedirigeVersLaConnexion(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseRedirects(
            null,
            302,
            sprintf('%s doit être inaccessible sans authentification.', $url),
        );
        self::assertStringContainsString(
            '/login',
            (string) $client->getResponse()->headers->get('Location'),
            sprintf('%s doit renvoyer vers la page de connexion.', $url),
        );
    }

    /**
     * Le pendant : les parcours publics doivent rester ouverts. Une règle access_control
     * trop large les fermerait, et plus aucun licencié ne pourrait remplir son dossier.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesPubliques(): iterable
    {
        $urls = [
            '/login',
            '/mentions-legales',
            '/politique-de-confidentialite',
            // UUID inexistant : la page « lien expiré » s'affiche, mais sans redirection
            // vers /login — c'est ce que ce test vérifie.
            '/inscription/00000000-0000-4000-8000-000000000000',
            '/dirigeant/00000000-0000-4000-8000-000000000000',
            '/attestation-cle/00000000-0000-4000-8000-000000000000',
        ];

        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('pagesPubliques')]
    public function testUnePagePubliqueResteAccessible(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful(sprintf('%s doit rester accessible sans authentification.', $url));
    }

    public function testLeWebhookHelloAssoResteAccessibleSansAuthentification(): void
    {
        $client = static::createClient();
        $client->request('POST', '/webhook/helloasso', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseIsSuccessful('Le webhook doit répondre 200 même sur un corps inexploitable, sinon HelloAsso rejoue.');
    }

    /** Une fois connecté, tout admin accède à l'espace : il n'y a pas de rôle plus fin. */
    public function testUnAdminConnecteAccedeAuTableauDeBord(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-acces@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
    }

    /**
     * Garde-fou : aucune route admin ne doit échapper au balayage ci-dessus sans qu'on
     * s'en aperçoive. Si ce test échoue, ajouter la nouvelle route à pagesAdmin().
     */
    public function testAucuneRouteAdminSansParametreNEchappeAuBalayage(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        $couvertes = array_map(static fn (array $cas): string => $cas[0], iterator_to_array(self::pagesAdmin()));
        $oubliees = [];

        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            $methods = $route->getMethods();

            if (!str_starts_with($path, '/admin') || str_contains($path, '{')) {
                continue;
            }
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }
            if (!in_array($path, $couvertes, true)) {
                $oubliees[] = $path;
            }
        }

        self::assertSame([], $oubliees, 'Routes admin non couvertes par le test d\'accès : ' . implode(', ', $oubliees));
    }
}

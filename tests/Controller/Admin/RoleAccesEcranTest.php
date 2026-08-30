<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;
use App\Service\Compte\RolesSysteme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran de composition d'un rôle.
 *
 * Le point sensible est le passage du navigateur au serveur : les cases verrouillées par
 * Alpine sont *désactivées*, donc non envoyées, et un champ caché prend leur place. Si ce
 * relais casse, le verrou retire le droit qu'il vient d'accorder — d'où le test qui poste
 * une écriture toute seule et vérifie que sa lecture arrive quand même.
 */
final class RoleAccesEcranTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setSuperAdmin(true)->setEmail('roles-ecran@example.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testLaListeMontreLesRolesLivresEtLeurNombreDeComptes(): void
    {
        $crawler = $this->client->request('GET', '/admin/club/roles');

        self::assertResponseIsSuccessful();

        $html = $crawler->html();
        foreach (array_keys(RolesSysteme::definitions()) as $nom) {
            self::assertStringContainsString($nom, $html);
        }
    }

    public function testLeFormulaireProposeToutLeCatalogueGroupeParDomaine(): void
    {
        $crawler = $this->client->request('GET', '/admin/club/roles/nouveau');

        self::assertResponseIsSuccessful();
        self::assertCount(
            count(Permission::cases()),
            $crawler->filter('input[type="checkbox"][name="permissions[]"]'),
            'Chaque permission du catalogue a sa case.',
        );
    }

    /**
     * L'écran n'a pas son propre habillage : il pose les composants du socle commun. Une
     * page qui redéfinit ses champs finit par ne plus ressembler au reste de l'application —
     * c'est exactement ce qui était arrivé à la première version.
     */
    public function testLEcranReposeSurLesComposantsCommuns(): void
    {
        $crawler = $this->client->request('GET', '/admin/club/roles/nouveau');

        self::assertCount(1, $crawler->filter('input#role-nom.field-input'), 'Le nom est un champ du socle.');
        self::assertGreaterThan(0, $crawler->filter('.card .card-header .card-titre')->count());
        self::assertGreaterThan(0, $crawler->filter('label.field-checkbox')->count());
    }

    /**
     * Le serveur complète la sélection : c'est lui qui fait foi, l'aide de saisie du
     * navigateur n'est qu'un confort.
     */
    public function testUneEcriturePosteeSeuleArriveAvecSaLecture(): void
    {
        $this->client->request('POST', '/admin/club/roles/nouveau', [
            '_token' => $this->jeton('/admin/club/roles/nouveau'),
            'nom' => 'Caisse du samedi',
            'permissions' => [Permission::PAIEMENT_ENCAISSER->value],
        ]);

        self::assertResponseRedirects('/admin/club/roles');

        $role = $this->em->getRepository(RoleAcces::class)->findOneBy(['nom' => 'Caisse du samedi']);

        self::assertNotNull($role);
        self::assertTrue($role->a(Permission::PAIEMENT_ENCAISSER));
        self::assertTrue($role->a(Permission::PAIEMENT_LIRE));
        self::assertTrue($role->a(Permission::EFFECTIF_LIRE));
    }

    public function testUnNomDejaPrisRevientSurLeFormulaireAvecLaSaisie(): void
    {
        $this->client->request('POST', '/admin/club/roles/nouveau', [
            '_token' => $this->jeton('/admin/club/roles/nouveau'),
            'nom' => RolesSysteme::TRESORERIE,
            'permissions' => [Permission::CLE_LIRE->value],
        ]);

        self::assertResponseIsSuccessful('On revient au formulaire, on ne redirige pas.');
        self::assertStringContainsString('existe déjà', (string) $this->client->getResponse()->getContent());
    }

    public function testUnRoleSeModifieDepuisSonEcran(): void
    {
        $role = (new RoleAcces())->setNom('À corriger')->setPermissions([Permission::CLE_LIRE]);
        $this->em->persist($role);
        $this->em->flush();

        $id = $role->getId();
        $url = '/admin/club/roles/' . $id . '/modifier';

        $this->client->request('POST', $url, [
            '_token' => $this->jeton($url),
            'nom' => 'Clés du local',
            'permissions' => [Permission::CLE_GERER->value],
        ]);

        self::assertResponseRedirects('/admin/club/roles');

        // La requête a fermé son gestionnaire d'entités : on relit depuis la base.
        $this->em->clear();
        $relu = $this->em->getRepository(RoleAcces::class)->find($id);

        self::assertNotNull($relu);
        self::assertSame('Clés du local', $relu->getNom());
        self::assertTrue($relu->a(Permission::CLE_LIRE));
    }

    /**
     * Le bouton reste, cadenassé, et porte son motif en infobulle : le faire disparaître
     * laisserait l'admin chercher ce qu'il a mal fait.
     */
    public function testUnRoleLivrePorteUnBoutonDeSuppressionCadenasse(): void
    {
        $crawler = $this->client->request('GET', '/admin/club/roles');

        $ligne = $crawler->filter('.roles-row')->reduce(
            static fn ($n): bool => str_contains($n->text(), RolesSysteme::RESPONSABLE_FOOT),
        );

        self::assertCount(1, $ligne);
        self::assertCount(0, $ligne->filter('form'), 'Aucun formulaire de suppression n\'est posté.');

        $bouton = $ligne->filter('.roles-supprimer-verrouille');

        self::assertCount(1, $bouton);
        self::assertNotNull($bouton->attr('disabled'));
        self::assertStringContainsString('pas supprimable', (string) $bouton->attr('title'));
    }

    private function jeton(string $url): string
    {
        return (string) $this->client->request('GET', $url)
            ->filter('input[name="_token"]')
            ->attr('value');
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DirigeantRole;
use App\Repository\DirigeantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le rôle de dirigeant est un référentiel fermé : trois valeurs, aucune création possible depuis
 * l'UI. Ces tests verrouillent les deux moitiés du contrat — le formulaire refuse une valeur hors
 * enum, et le combobox est rendu en mode « liste fermée ».
 */
final class DirigeantRoleEnumTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLeChampRoleProposeLesTroisRolesSansPouvoirEnCreer(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $crawler = $client->request('GET', '/admin/effectif/dirigeants/nouveau');

        self::assertResponseIsSuccessful();

        $xData = $crawler->filter('div[x-data^="textCombobox"]')->attr('x-data');
        self::assertNotNull($xData);
        self::assertMatchesRegularExpression(
            '/textCombobox\(.*,\s*false\s*\)/s',
            $xData,
            'Le combobox du rôle doit être monté avec allow_create à false.',
        );

        // Les suggestions sont sérialisées en JSON dans l'attribut : on compare à l'identique.
        foreach (DirigeantRole::cases() as $role) {
            self::assertStringContainsString(json_encode(['value' => $role->value, 'label' => $role->label()]), $xData);
        }
    }

    public function testLEditionPreselectionneLeRoleCourant(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $dirigeant = $this->makeDirigeant('CHEF', DirigeantRole::RESPONSABLE_FOOT);

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid() . '/modifier');

        self::assertResponseIsSuccessful();
        $xData = (string) $crawler->filter('div[x-data^="textCombobox"]')->attr('x-data');
        self::assertMatchesRegularExpression(
            '/textCombobox\(\s*\[.*\],\s*"responsable_foot",/s',
            $xData,
            'Le rôle courant doit être passé en valeur pré-remplie du combobox.',
        );
    }

    /** Non-régression : ailleurs, la prop garde sa valeur par défaut et la création reste possible. */
    public function testLesAutresCombobxGardentLaCreationActivee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $crawler = $client->request('GET', '/admin/stock/items/nouveau');

        self::assertResponseIsSuccessful();

        $xData = $crawler->filter('div[x-data^="textCombobox"]')->first()->attr('x-data');
        self::assertMatchesRegularExpression('/textCombobox\(.*,\s*true\s*\)/s', (string) $xData);
    }

    public function testUnRoleValideEstEnregistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->submitNouveauDirigeant($client, DirigeantRole::RESPONSABLE_FOOT->value);

        self::assertResponseRedirects();
        self::assertSame(DirigeantRole::RESPONSABLE_FOOT, $this->findDirigeant('BUREAU')?->getRole());
    }

    public function testUnRoleHorsEnumEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->submitNouveauDirigeant($client, 'grand_manitou');

        // 422 : le formulaire est réaffiché avec ses erreurs, pas une 500 ni une redirection.
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findDirigeant('BUREAU'), 'Aucun dirigeant ne doit être créé.');
    }

    public function testUnRoleVideEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->submitNouveauDirigeant($client, '');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findDirigeant('BUREAU'));
    }

    public function testLaRouteDeCreationDeRoleNExistePlus(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('POST', '/admin/dirigeant-roles', ['label' => 'Grand Manitou']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeFiltreRoleDeLaListe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeDirigeant('CHEF', DirigeantRole::RESPONSABLE_FOOT);
        $this->makeDirigeant('BENEVOLE', DirigeantRole::DIRIGEANT);

        $client->request('GET', '/admin/effectif/dirigeants?role=' . DirigeantRole::RESPONSABLE_FOOT->value);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('CHEF', $html);
        self::assertStringNotContainsString('BENEVOLE', $html);
    }

    /** Un ancien id numérique mémorisé en session ne doit ni filtrer, ni faire planter la page. */
    public function testUnAncienIdNumeriqueEnFiltreEstIgnore(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeDirigeant('CHEF', DirigeantRole::RESPONSABLE_FOOT);
        $this->makeDirigeant('BENEVOLE', DirigeantRole::DIRIGEANT);

        $client->request('GET', '/admin/effectif/dirigeants?role=42');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('CHEF', $html);
        self::assertStringContainsString('BENEVOLE', $html);
    }

    private function submitNouveauDirigeant(KernelBrowser $client, string $role): void
    {
        $crawler = $client->request('GET', '/admin/effectif/dirigeants/nouveau');
        $form = $crawler->selectButton('Ajouter le dirigeant')->form();

        $form['dirigeant[nom]'] = 'BUREAU';
        $form['dirigeant[prenom]'] = 'Martine';
        $form['dirigeant[role]'] = $role;

        $client->submit($form);
    }

    private function findDirigeant(string $nom): ?Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(DirigeantRepository::class)
            ->findOneBy(['nom' => $nom, 'season' => $this->season->getId()]);
    }

    private function makeDirigeant(string $nom, DirigeantRole $role): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $dirigeant = (new Dirigeant())->setNom($nom)->setPrenom('Test')->setSeason($this->season)->setRole($role);
        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-role@example.com')->setPassword('x');

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

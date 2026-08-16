<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Correction des coordonnées d'un licencié importé.
 *
 * L'écran d'identité est réservé aux fiches saisies à la main — le nom et le numéro de licence
 * appartiennent à FootClubs. Mais une adresse mail fausse doit se corriger tout de suite : le
 * lien d'inscription en dépend, et l'export ne se corrige parfois qu'après validation à la ligue.
 */
final class CorrectionCoordonneesTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLEcranEstOuvertAUnLicencieImporte(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->creerLicencieImporte();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid() . '/coordonnees');

        self::assertResponseIsSuccessful();
    }

    public function testLIdentiteResteFermeeAUnLicencieImporte(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->creerLicencieImporte();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid() . '/identite');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEnregistrerUneNouvelleAdressePoseLeVerrou(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->creerLicencieImporte();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid() . '/coordonnees');
        $client->submitForm('Enregistrer', ['contact[email]' => 'bonne.adresse@example.test']);

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $licencie->getUuid());

        $relu = $this->relire($licencie);
        self::assertSame('bonne.adresse@example.test', $relu->getEmail());
        self::assertTrue($relu->isEmailManuel());
        self::assertFalse($relu->isTelephoneManuel(), 'Le téléphone n\'a pas été touché');
    }

    /** La fiche doit dire pourquoi l'import ne change plus rien, et offrir de lui rendre la main. */
    public function testLaFicheOffreDeRendreLaMainAFootClubs(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->creerLicencieImporte();
        $licencie->setEmail('bonne.adresse@example.test')->setEmailManuel(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('corrigé à la main', (string) $crawler->html());

        $client->submit($crawler->selectButton('Reprendre FootClubs')->form());

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $licencie->getUuid());
        self::assertFalse($this->relire($licencie)->isEmailManuel());
    }

    /* ── Outils ── */

    private function creerLicencieImporte(): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('2003-04-12'))
            ->setCategory($category)
            ->setSeason($this->season)
            ->setNumLicence('111')
            ->setEmail('faute.de.frappe@example.test');

        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::IMPORTED);

        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        return $licencie;
    }

    private function relire(Licencie $licencie): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $uuid = $licencie->getUuid();
        $em->clear();

        $relu = $em->getRepository(Licencie::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Licencie::class, $relu);

        return $relu;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-coordonnees@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

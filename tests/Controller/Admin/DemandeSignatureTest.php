<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bouton « Demander la signature » sur la fiche d'un joueur.
 *
 * Il n'a de sens que pour un dossier déjà complet : c'est le seul cas où le parcours
 * d'inscription ne repassera plus et où un document ajouté depuis resterait sans
 * signature. Tant que le formulaire n'a pas été rempli, c'est le lien d'inscription
 * qui s'impose — il collecte les signatures avec le reste.
 */
final class DemandeSignatureTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLeBoutonApparaitQuandUnDocumentAEteAjouteApresCoup(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        (new DocumentFixtures($em))->documentLicencie($this->season, titre: 'Charte communication');
        $licencie = $this->licencie($em, LicenceStatus::VALIDATED, formCompletee: true);
        $em->flush();
        $em->clear();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Demander la signature', (string) $client->getResponse()->getContent());
    }

    public function testLeBoutonNApparaitPasSurUnDossierJamaisRempli(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        (new DocumentFixtures($em))->documentLicencie($this->season);
        $licencie = $this->licencie($em, LicenceStatus::IMPORTED, formCompletee: false);
        $em->flush();
        $em->clear();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Demander la signature', $html);
        self::assertStringContainsString('Envoyer le lien', $html, 'C\'est le lien d\'inscription qui s\'impose.');
    }

    public function testLeBoutonDisparaitUneFoisToutSigne(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentLicencie($this->season);
        $licencie = $this->licencie($em, LicenceStatus::VALIDATED, formCompletee: true);
        $em->flush();

        $fixtures->signerParLicencie($document, $licencie);
        $em->flush();
        $em->clear();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Demander la signature', (string) $client->getResponse()->getContent());
    }

    /** L'envoi rouvre la fenêtre de 30 jours : sans ça le lien du mail serait mort-né. */
    public function testLEnvoiRouvreLeLienPublic(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        (new DocumentFixtures($em))->documentLicencie($this->season);
        $licencie = $this->licencie($em, LicenceStatus::VALIDATED, formCompletee: true);
        $em->flush();

        self::assertFalse($licencie->isFormTokenValid());

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/demander-signature', [
            '_token' => $this->tokenDeLaFiche($client, $uuid),
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $uuid);
        self::assertTrue($em->find(Licencie::class, $licencie->getUuid())->isFormTokenValid());
    }

    private function tokenDeLaFiche(KernelBrowser $client, string $uuid): string
    {
        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);

        return $crawler->filter('form[action$="/demander-signature"] input[name="_token"]')->attr('value');
    }

    private function licencie(EntityManagerInterface $em, LicenceStatus $status, bool $formCompletee): Licencie
    {
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setEmail('thomas@example.test')
            ->setCategory($category)
            ->setSeason($this->season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($status)
            ->setFormCompletedAt($formCompletee ? new \DateTimeImmutable('-2 months') : null);

        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        return $licencie;
    }

    private function loginAdmin(KernelBrowser $client): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-signature@example.com')->setPassword('x');

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $em;
    }
}

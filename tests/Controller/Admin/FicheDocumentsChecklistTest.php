<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
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
 * Checklist des fiches licencié et dirigeant. Elle n'énumère plus des documents codés
 * en dur mais ceux que la saison demande réellement à la personne : ajouter une charte
 * doit la faire apparaître, sans toucher à ces écrans.
 */
final class FicheDocumentsChecklistTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLaFicheDirigeantListeLesDocumentsAttendusEtLeurEtat(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $reglement = $fixtures->documentDirigeant($this->season);
        $charte = $fixtures->documentDirigeant(
            $this->season,
            code: 'charte_communication',
            titre: 'Charte communication',
            sortOrder: 20,
        );

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')->setPrenom('Kevin')->setSeason($this->season);

        $em->persist($dirigeant);
        $em->flush();

        $fixtures->signerParDirigeant($reglement, $dirigeant, 'drive-file-id');
        $em->flush();

        $client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid());
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Règlement intérieur des dirigeants', $html);
        self::assertStringContainsString('Archivé sur Drive', $html);
        self::assertStringContainsString('Charte communication', $html);
        self::assertStringContainsString('En attente', $html, 'La charte non signée reste en attente.');
    }

    public function testLaFicheDirigeantSignaleUnUploadDriveEnAttente(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $reglement = $fixtures->documentDirigeant($this->season);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')->setPrenom('Kevin')->setSeason($this->season);

        $em->persist($dirigeant);
        $em->flush();

        // Chemin local absolu : le PDF est signé mais pas encore archivé.
        $fixtures->signerParDirigeant($reglement, $dirigeant, '/var/www/html/var/pdfs/abc.pdf');
        $em->flush();

        $client->request('GET', '/admin/effectif/dirigeants/' . $dirigeant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Upload en attente', (string) $client->getResponse()->getContent());
    }

    public function testLaFicheLicencieListeLesDocumentsAttendusEtLeurEtat(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $reglement = $fixtures->documentLicencie($this->season);

        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($this->season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable());

        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        // La relation inverse Licencie->DossierClub n'est pas hydratée par le persist :
        // sans ce clear, la page ne verrait pas de dossier club.
        $em->clear();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Règlement intérieur', $html);
        self::assertStringContainsString('En attente', $html);

        $fixtures->signerParLicencie(
            $em->find(DocumentSignable::class, $reglement->getId()),
            $em->find(Licencie::class, $licencie->getUuid()),
        );
        $em->flush();

        $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            (new \DateTimeImmutable())->format('d/m/Y'),
            (string) $client->getResponse()->getContent(),
            'La date de signature est affichée.',
        );
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-fiches@example.com')->setPassword('x');

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Demander les signatures », joueurs et dirigeants.
 *
 * C'est lui qui rend le système non manuel : un document ajouté en cours de saison ne se
 * voit pas, les dossiers concernés étant complets et leurs liens consommés.
 *
 * Il vit dans l'effectif, avec la population, et non dans « Documents à signer » — ce
 * dernier sert à préparer les documents, pas à relancer les gens.
 */
final class DemanderSignaturesTest extends WebTestCase
{
    private ?Season $season = null;

    /**
     * Une ligne par personne, jamais par document : quelqu'un à qui il en manque deux
     * reçoit un seul mail, son parcours les lui présentant tous les deux.
     */
    public function testUnJoueurAQuiIlManqueDeuxDocumentsNApparaitQuUneFois(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        $fixtures = new DocumentFixtures($em);
        $fixtures->documentLicencie($this->season, titre: 'Règlement intérieur');
        $fixtures->documentLicencie($this->season, code: 'charte', titre: 'Charte communication', sortOrder: 20);

        $this->licencie($em, 'MARTIN', formCompletee: true);
        $em->flush();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/demander-signatures');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="personnes[]"]')->count());
        self::assertStringContainsString('Règlement intérieur', $html);
        self::assertStringContainsString('Charte communication', $html);
    }

    public function testLaListeEcarteLesSignatairesEtLesDossiersNonTermines(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentLicencie($this->season);

        $this->licencie($em, 'MARTIN', formCompletee: true);
        $dejaSigne = $this->licencie($em, 'DUPONT', formCompletee: true);
        $this->licencie($em, 'LAGRANGE', formCompletee: false);
        $em->flush();

        $fixtures->signerParLicencie($document, $dejaSigne);
        $em->flush();

        $client->request('GET', '/admin/effectif/joueurs/demander-signatures');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARTIN', $html);
        self::assertStringNotContainsString('DUPONT', $html, 'Qui a signé n\'est pas relancé.');
        self::assertStringNotContainsString('LAGRANGE', $html, 'Le dossier non terminé attend son formulaire.');
    }

    /** Une case décochée ne doit faire partir aucun mail — c'est tout l'objet de l'écran. */
    public function testSeulesLesPersonnesCocheesSontRelancees(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        (new DocumentFixtures($em))->documentLicencie($this->season);

        $retenu = $this->licencie($em, 'MARTIN', formCompletee: true);
        $ecarte = $this->licencie($em, 'DUPONT', formCompletee: true);
        $em->flush();

        $uuidRetenu = $retenu->getUuid();
        $uuidEcarte = $ecarte->getUuid();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/demander-signatures');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/effectif/joueurs/demander-signatures', [
            '_token' => $token,
            'personnes' => [(string) $uuidRetenu],
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs');

        $em->clear();
        self::assertNotNull($em->find(Licencie::class, $uuidRetenu)->getFormTokenExpiresAt(), 'Le lien coché est rouvert.');
        self::assertNull($em->find(Licencie::class, $uuidEcarte)->getFormTokenExpiresAt(), 'Le lien décoché ne bouge pas.');
    }

    /** Un uuid ajouté au formulaire ne doit pas faire écrire à quelqu'un qui n'était pas proposé. */
    public function testUnUuidNonProposeNeDeclencheAucunEnvoi(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentLicencie($this->season);

        $this->licencie($em, 'MARTIN', formCompletee: true);
        $dejaSigne = $this->licencie($em, 'DUPONT', formCompletee: true);
        $em->flush();

        $fixtures->signerParLicencie($document, $dejaSigne);
        $em->flush();

        $uuidSignataire = $dejaSigne->getUuid();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/demander-signatures');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/effectif/joueurs/demander-signatures', [
            '_token' => $token,
            'personnes' => [(string) $uuidSignataire],
        ]);

        $em->clear();
        self::assertNull($em->find(Licencie::class, $uuidSignataire)->getFormTokenExpiresAt());
    }

    public function testLEcranDirigeantsRelanceLesNonSignataires(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentDirigeant($this->season);

        $aRelancer = $this->dirigeant($em, 'MARTIN', 'kevin@example.com');
        $dejaSigne = $this->dirigeant($em, 'DUPONT', 'marie@example.com');
        $sansEmail = $this->dirigeant($em, 'LAGRANGE', null);
        $em->flush();

        $fixtures->signerParDirigeant($document, $dejaSigne);
        $em->flush();

        $uuidRelance = $aRelancer->getUuid();
        $uuidSansEmail = $sansEmail->getUuid();

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/demander-signatures');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARTIN', $html);
        self::assertStringContainsString('LAGRANGE', $html, 'Sans email, la personne reste visible — à prévenir autrement.');
        self::assertStringNotContainsString('DUPONT', $html);

        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/effectif/dirigeants/demander-signatures', [
            '_token' => $token,
            'personnes' => [(string) $uuidRelance, (string) $uuidSansEmail],
        ]);

        self::assertResponseRedirects('/admin/effectif/dirigeants');

        $em->clear();
        self::assertNotNull($em->find(Dirigeant::class, $uuidRelance)->getFormTokenExpiresAt());
        self::assertNull($em->find(Dirigeant::class, $uuidSansEmail)->getFormTokenExpiresAt(), 'Sans email, aucun lien ne part.');
    }

    /** Une licence administrative n'attend aucun document : elle ne doit jamais être relancée. */
    public function testUneLicenceAdministrativeNEstJamaisListee(): void
    {
        $client = static::createClient();
        $em = $this->loginAdmin($client);

        (new DocumentFixtures($em))->documentDirigeant($this->season);

        $president = $this->dirigeant($em, 'LAGRANGE', 'president@example.com');
        $president->setLicenceAdministrative(true);
        $em->flush();

        $client->request('GET', '/admin/effectif/dirigeants/demander-signatures');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('LAGRANGE', (string) $client->getResponse()->getContent());
    }

    private function licencie(EntityManagerInterface $em, string $nom, bool $formCompletee): Licencie
    {
        // Le code de catégorie est unique et limité à 10 caractères.
        $category = (new Category())
            ->setCode(substr('S' . $nom, 0, 10))->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setEmail(strtolower($nom) . '@example.com')
            ->setCategory($category)
            ->setSeason($this->season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setFormCompletedAt($formCompletee ? new \DateTimeImmutable('-2 months') : null);

        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        return $licencie;
    }

    private function dirigeant(EntityManagerInterface $em, string $nom, ?string $email): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($this->season)
            ->setEmail($email);

        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
    }

    private function loginAdmin(KernelBrowser $client): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-relance@example.com')->setPassword('x');

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $em;
    }
}

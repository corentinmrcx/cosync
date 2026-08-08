<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\DocumentSignableRepository;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Administration des documents signables. L'enjeu central reste le même qu'avec les
 * deux règlements codés en dur : chaque document doit rester un texte indépendant,
 * qu'aucun enregistrement voisin n'écrase.
 */
final class DocumentSignableCrudTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLaListeAfficheLesDocumentsDeLaSaison(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $fixtures = new DocumentFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $fixtures->documentLicencie($this->season);
        $fixtures->documentDirigeant($this->season);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/admin/config/documents');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Règlement intérieur', $html);
        self::assertStringContainsString('Règlement intérieur des dirigeants', $html);
    }

    /**
     * Le compte des signatures manquantes vaut aussi pour les licenciés. Il ne l'était
     * pas : faute de compte, la liste affichait « Tout le monde a signé » sur le
     * règlement des licenciés alors que personne n'avait signé.
     */
    public function testLaListeCompteLesLicenciesQuiNOntPasSigne(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentLicencie($this->season);

        $signataire = $this->makeLicencie('DUPONT');
        $this->makeLicencie('MARTIN');
        $em->flush();

        $client->request('GET', '/admin/config/documents');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('2 en attente', $html);
        self::assertStringNotContainsString('Tout le monde a signé', $html);

        $fixtures->signerParLicencie($document, $signataire);
        $em->flush();

        $client->request('GET', '/admin/config/documents');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('1 en attente', $html);
        self::assertStringNotContainsString('Tout le monde a signé', $html);
    }

    public function testLaListeAnnonceLeDocumentEntierementSigne(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentLicencie($this->season);
        $licencie = $this->makeLicencie('DUPONT');
        $em->flush();

        $fixtures->signerParLicencie($document, $licencie);
        $em->flush();

        $client->request('GET', '/admin/config/documents');

        self::assertStringContainsString('Tout le monde a signé', (string) $client->getResponse()->getContent());
    }

    public function testUneSaisonSansPersonneNAnnoncePasUnDocumentSigne(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        (new DocumentFixtures($em))->documentLicencie($this->season);
        $em->flush();

        $client->request('GET', '/admin/config/documents');

        self::assertStringNotContainsString('Tout le monde a signé', (string) $client->getResponse()->getContent());
    }

    public function testCreerUnDocumentLeRendImmediatementExigible(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/config/documents/nouveau');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form#document-form input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/config/documents/nouveau', [
            '_token'       => $token,
            'titre'        => 'Charte d\'engagement — Communication',
            'libelle'      => 'la charte d\'engagement communication du Foyer de Soudron',
            'cible'        => 'dirigeant',
            'contenu_html' => '<p>Je m engage.</p>',
            'actif'        => '1',
        ]);

        self::assertResponseRedirects('/admin/config/documents');

        $document = $this->findByCode('charte_d_engagement_communication');

        self::assertNotNull($document, 'Le code est dérivé du titre.');
        self::assertSame('<p>Je m engage.</p>', $document->getContenuHtml());
        self::assertTrue($document->isActif());
        self::assertTrue($document->viseTousLesDirigeants(), 'Sans rôle ni personne cochée, le document vise tout le monde.');
    }

    public function testEnregistrerUnDocumentNEcrasePasLesAutres(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures  = new DocumentFixtures($em);
        $joueurs   = $fixtures->documentLicencie($this->season, contenuHtml: '<p>Texte joueurs</p>');
        $dirigeant = $fixtures->documentDirigeant($this->season, contenuHtml: '<p>Texte dirigeants</p>');
        $em->flush();

        $url     = '/admin/config/documents/' . $dirigeant->getId() . '/modifier';
        $crawler = $client->request('GET', $url);
        $token   = $crawler->filter('form#document-form input[name="_token"]')->attr('value');

        $client->request('POST', $url, [
            '_token'       => $token,
            'titre'        => 'Règlement intérieur des dirigeants',
            'libelle'      => 'le règlement intérieur des dirigeants du Foyer de Soudron',
            'cible'        => 'dirigeant',
            'contenu_html' => '<p>Texte dirigeants v2</p>',
            'actif'        => '1',
        ]);

        self::assertResponseRedirects('/admin/config/documents');

        $em->clear();
        self::assertSame('<p>Texte dirigeants v2</p>', $em->find(DocumentSignable::class, $dirigeant->getId())->getContenuHtml());
        self::assertSame('<p>Texte joueurs</p>', $em->find(DocumentSignable::class, $joueurs->getId())->getContenuHtml());
    }

    public function testUnTokenCsrfInvalideNEnregistreRien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $document = (new DocumentFixtures($em))->documentDirigeant($this->season, contenuHtml: '<p>Original</p>');
        $em->flush();
        $id = $document->getId();

        $client->request('POST', '/admin/config/documents/' . $id . '/modifier', [
            '_token'       => 'invalide',
            'titre'        => 'Piraté',
            'libelle'      => 'le document piraté',
            'cible'        => 'dirigeant',
            'contenu_html' => '<p>Piraté</p>',
        ]);

        self::assertResponseRedirects('/admin/config/documents/' . $id . '/modifier');

        $em->clear();
        self::assertSame('<p>Original</p>', $em->find(DocumentSignable::class, $id)->getContenuHtml());
    }

    public function testLApercuPdfEstTelechargeable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $document = (new DocumentFixtures($em))->documentDirigeant($this->season);
        $em->flush();

        $client->request('GET', '/admin/config/documents/' . $document->getId() . '/apercu');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testUnDocumentSigneNePeutPlusEtreSupprime(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures  = new DocumentFixtures($em);
        $document  = $fixtures->documentDirigeant($this->season);
        $dirigeant = (new \App\Entity\Dirigeant())
            ->setNom('MARTIN')->setPrenom('Kevin')->setSeason($this->season);

        $em->persist($dirigeant);
        $em->flush();

        $fixtures->signerParDirigeant($document, $dirigeant);
        $em->flush();
        $id = $document->getId();

        // La liste n'offre plus le bouton dès qu'il y a une signature ; on force la
        // requête pour vérifier que le contrôleur refuse aussi, et pas seulement l'UI.
        $client->request('GET', '/admin/config/documents');
        self::assertStringNotContainsString(
            '/admin/config/documents/' . $id . '/supprimer',
            (string) $client->getResponse()->getContent(),
        );

        $client->request('POST', '/admin/config/documents/' . $id . '/supprimer', [
            '_token' => $this->csrf($client, 'document_delete_' . $id),
        ]);

        self::assertResponseRedirects('/admin/config/documents');

        $em->clear();
        self::assertNotNull($em->find(DocumentSignable::class, $id), 'Supprimer emporterait la signature recueillie.');
    }

    /**
     * Relance groupée : c'est elle qui rend le système non manuel. Un document ajouté
     * en cours de saison ne se voit pas — les dossiers concernés étaient complets, donc
     * leurs liens consommés.
     */
    public function testLaRelanceRenvoieUnLienAuxSeulsDirigeantsEnAttente(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $fixtures = new DocumentFixtures($em);
        $document = $fixtures->documentDirigeant($this->season);

        $aRelancer = $this->makeDirigeant('MARTIN', 'kevin@example.com');
        $dejaSigne = $this->makeDirigeant('DUPONT', 'marie@example.com');
        $sansEmail = $this->makeDirigeant('LAGRANGE', null);
        $em->flush();

        $fixtures->signerParDirigeant($document, $dejaSigne);
        $em->flush();

        $url     = '/admin/config/documents/' . $document->getId() . '/relancer';
        $crawler = $client->request('GET', $url);
        $html    = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARTIN', $html);
        self::assertStringContainsString('LAGRANGE', $html);
        self::assertStringNotContainsString('DUPONT', $html, 'Qui a signé n\'est pas relancé.');

        $uuidRelance   = $aRelancer->getUuid();
        $uuidSansEmail = $sansEmail->getUuid();
        $token         = $crawler->filter('form#relance-form input[name="_token"]')->attr('value');

        $client->request('POST', $url, ['_token' => $token]);

        self::assertResponseRedirects('/admin/config/documents');

        $em->clear();

        self::assertNotNull(
            $em->find(\App\Entity\Dirigeant::class, $uuidRelance)->getFormTokenExpiresAt(),
            'Le lien est régénéré pour 30 jours.',
        );
        self::assertNull(
            $em->find(\App\Entity\Dirigeant::class, $uuidSansEmail)->getFormTokenExpiresAt(),
            'Sans email, aucun lien ne part.',
        );
    }

    public function testLaRelanceRefuseUnDocumentDestineAuxLicencies(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $document = (new DocumentFixtures($em))->documentLicencie($this->season);
        $em->flush();

        $client->request('GET', '/admin/config/documents/' . $document->getId() . '/relancer');

        self::assertResponseRedirects('/admin/config/documents');
    }

    private function makeLicencie(string $nom): \App\Entity\Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Le code de catégorie est unique et limité à 10 caractères.
        $category = (new \App\Entity\Category())
            ->setCode(substr('S' . $nom, 0, 10))->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new \App\Entity\Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($this->season);

        $em->persist($category);
        $em->persist($licencie);

        return $licencie;
    }

    private function makeDirigeant(string $nom, ?string $email): \App\Entity\Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $dirigeant = (new \App\Entity\Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($this->season)
            ->setEmail($email);

        $em->persist($dirigeant);

        return $dirigeant;
    }

    public function testLEcranExigeUneAuthentification(): void
    {
        $client = static::createClient();
        $this->makeSeason();

        $client->request('GET', '/admin/config/documents');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * Le gestionnaire de jetons lit la session, qui n'existe que le temps d'une requête :
     * on lui remet celle du client avant de demander le jeton.
     */
    private function csrf(KernelBrowser $client, string $id): string
    {
        $request = new Request();
        $request->setSession($client->getRequest()->getSession());
        self::getContainer()->get(RequestStack::class)->push($request);

        return (string) self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function findByCode(string $code): ?DocumentSignable
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(DocumentSignableRepository::class)
            ->findOneByCode($em->find(Season::class, $this->season->getId()), $code);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-documents@example.com')->setPassword('x');

        $this->makeSeason();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function makeSeason(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $em->persist($this->season);
        $em->flush();
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chaque parcours public ne doit afficher que les documents qui le concernent.
 * La régression d'origine est celle où les dirigeants se voyaient présenter le
 * règlement des joueurs ; s'y ajoute le ciblage par rôle et par personne.
 */
final class DocumentParParcoursTest extends WebTestCase
{
    private const TEXTE_JOUEURS = '<p>Engagement reserve aux joueurs du club.</p>';
    private const TEXTE_DIRIGEANTS = '<p>Engagement reserve aux dirigeants du club.</p>';
    private const TEXTE_CHARTE = '<p>Charte de communication reservee au charge de com.</p>';

    public function testLeParcoursDirigeantAfficheLeReglementDesDirigeants(): void
    {
        $client = static::createClient();
        $uuid   = $this->createDirigeant();

        $client->request('GET', '/dirigeant/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Règlement intérieur des dirigeants', $html);
        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    public function testLeParcoursLicencieAfficheToujoursLeReglementDesJoueurs(): void
    {
        $client = static::createClient();
        $uuid   = $this->createLicencie();

        $client->request('GET', '/inscription/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringNotContainsString(self::TEXTE_DIRIGEANTS, $html);
    }

    public function testUnDocumentNonRedigeInviteAContacterLeClub(): void
    {
        $client = static::createClient();
        $uuid   = $this->createDirigeant(texteDirigeant: null);

        $client->request('GET', '/dirigeant/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce document n\'a pas encore été rédigé', $html);
        // Le règlement des joueurs ne doit surtout pas servir de repli.
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    public function testUnDocumentCibleNEstDemandeQuAuxPersonnesDesignees(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);

        $season = $this->makeSeason();
        $charge = $this->makeDirigeant($season, 'DUPONT', 'Marie');
        $autre  = $this->makeDirigeant($season, 'MARTIN', 'Kevin');

        $fixtures->documentDirigeant($season, contenuHtml: self::TEXTE_DIRIGEANTS);

        $em->persist($season);
        $em->persist($charge);
        $em->persist($autre);
        $em->flush();

        // La charte ne vise nommément que la personne en charge de la communication.
        $fixtures->documentDirigeant(
            $season,
            code: 'charte_communication',
            titre: 'Charte d\'engagement — Communication',
            contenuHtml: self::TEXTE_CHARTE,
            dirigeants: [$charge],
            sortOrder: 20,
        );
        $em->flush();

        $uuidCharge = (string) $charge->getUuid();
        $uuidAutre  = (string) $autre->getUuid();
        $em->clear();

        $client->request('GET', '/dirigeant/' . $uuidCharge);
        $htmlCharge = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $htmlCharge);
        self::assertStringContainsString(self::TEXTE_CHARTE, $htmlCharge);
        self::assertStringContainsString('Étape ${displayStep} sur ${totalSteps}', $htmlCharge);

        $client->request('GET', '/dirigeant/' . $uuidAutre);
        $htmlAutre = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $htmlAutre);
        self::assertStringNotContainsString(self::TEXTE_CHARTE, $htmlAutre);
    }

    /**
     * Le bootstrap Alpine est écrit dans un attribut HTML délimité par des guillemets.
     * Un json_encode marqué |raw y injecte des guillemets bruts, referme l'attribut et
     * casse tout le composant — sans qu'aucune assertion sur le contenu ne le voie.
     */
    public function testLeBootstrapAlpineDuParcoursDirigeantEstUnAttributValide(): void
    {
        $client = static::createClient();
        $uuid   = $this->createDirigeant();

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);

        $this->assertBootstrapAlpineValide($crawler, (string) $client->getResponse()->getContent());
    }

    public function testLeBootstrapAlpineDuParcoursLicencieEstUnAttributValide(): void
    {
        $client = static::createClient();
        $uuid   = $this->createLicencie();

        $crawler = $client->request('GET', '/inscription/' . $uuid);

        $this->assertBootstrapAlpineValide($crawler, (string) $client->getResponse()->getContent());
    }

    /**
     * Dans le HTML brut, les guillemets du JSON doivent être échappés ; une fois
     * l'attribut décodé par le navigateur (ici le crawler), il doit être du JSON valide.
     */
    private function assertBootstrapAlpineValide(\Symfony\Component\DomCrawler\Crawler $crawler, string $htmlBrut): void
    {
        self::assertStringContainsString('documents: [{&quot;id&quot;:', $htmlBrut);
        self::assertStringNotContainsString('documents: [{"', $htmlBrut, 'Un guillemet brut refermerait l\'attribut x-data.');

        $xData = (string) $crawler->filter('.inscription-page')->attr('x-data');

        preg_match('/documents: (\[.*?\])/s', $xData, $m);
        self::assertNotEmpty($m, 'documents doit être présent dans le x-data.');

        $documents = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(1, $documents);
        self::assertArrayHasKey('id', $documents[0]);
    }

    public function testUnDocumentCibleParRoleSuitLeRoleDuDirigeant(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);

        $season      = $this->makeSeason();
        $responsable = $this->makeDirigeant($season, 'DUPONT', 'Marie', DirigeantRole::RESPONSABLE_FOOT);
        $benevole    = $this->makeDirigeant($season, 'MARTIN', 'Kevin', DirigeantRole::DIRIGEANT);

        $fixtures->documentDirigeant(
            $season,
            code: 'charte_bureau',
            titre: 'Charte du bureau',
            contenuHtml: self::TEXTE_CHARTE,
            roles: [DirigeantRole::RESPONSABLE_FOOT],
        );

        $em->persist($season);
        $em->persist($responsable);
        $em->persist($benevole);
        $em->flush();

        $uuidResponsable = (string) $responsable->getUuid();
        $uuidBenevole    = (string) $benevole->getUuid();
        $em->clear();

        $client->request('GET', '/dirigeant/' . $uuidResponsable);
        self::assertStringContainsString(self::TEXTE_CHARTE, (string) $client->getResponse()->getContent());

        $client->request('GET', '/dirigeant/' . $uuidBenevole);
        self::assertStringNotContainsString(self::TEXTE_CHARTE, (string) $client->getResponse()->getContent());
    }

    public function testUnDocumentDesactiveNEstPlusDemande(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);

        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season, 'MARTIN', 'Kevin');

        $fixtures->documentDirigeant($season, contenuHtml: self::TEXTE_DIRIGEANTS, actif: false);

        $em->persist($season);
        $em->persist($dirigeant);
        $em->flush();

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        // Plus aucun document attendu → le dossier est complet, redirection vers la confirmation.
        $client->request('GET', '/dirigeant/' . $uuid);
        self::assertResponseRedirects('/dirigeant/' . $uuid . '/confirmation');
    }

    private function createDirigeant(?string $texteDirigeant = self::TEXTE_DIRIGEANTS): string
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);
        $season   = $this->makeSeason();

        $dirigeant = $this->makeDirigeant($season, 'MARTIN', 'Kevin');

        $fixtures->documentLicencie($season, contenuHtml: self::TEXTE_JOUEURS);
        $fixtures->documentDirigeant($season, contenuHtml: $texteDirigeant);

        $em->persist($season);
        $em->persist($dirigeant);
        $em->flush();

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        return $uuid;
    }

    private function createLicencie(): string
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);
        $season   = $this->makeSeason();
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $fixtures->documentLicencie($season, contenuHtml: self::TEXTE_JOUEURS);
        $fixtures->documentDirigeant($season, contenuHtml: self::TEXTE_DIRIGEANTS);

        $em->persist($season);
        $em->persist($category);
        $em->persist($licencie);
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }

    private function makeDirigeant(
        Season $season,
        string $nom,
        string $prenom,
        DirigeantRole $role = DirigeantRole::DIRIGEANT,
    ): Dirigeant {
        return (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setSeason($season)
            ->setRole($role)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true)
            ->setVolontaireTransport(false)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));
    }

    private function makeSeason(): Season
    {
        return (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
    }
}

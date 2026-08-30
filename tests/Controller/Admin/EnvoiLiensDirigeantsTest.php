<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DirigeantRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * Envoi groupé des liens du formulaire dirigeant.
 *
 * Même règle que pour les licenciés : ni l'import ni la création manuelle n'écrivent d'
 * eux-mêmes, cet écran est le seul point de départ des liens pour un effectif entier.
 */
final class EnvoiLiensDirigeantsTest extends WebTestCase
{
    private Season $courante;

    public function testTousLesDirigeantsJoignablesSontCochesDOffice(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dupont = $this->creerDirigeant('DUPONT', 'thomas@example.test');
        $martin = $this->creerDirigeant('MARTIN', 'kevin@example.test');

        $client->submit($this->formulaire($client));

        self::assertResponseRedirects('/admin/effectif/dirigeants');
        self::assertCount(2, self::getMailerMessages());

        self::assertNotNull($this->relire($dupont)->getLinkSentAt());
        self::assertNotNull($this->relire($martin)->getLinkSentAt());
    }

    /** Décocher quelqu'un le met de côté sans le sortir de la liste : il repassera au prochain envoi. */
    public function testUnDirigeantDecocheNEstPasContacteEtResteEnAttente(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $garde = $this->creerDirigeant('DUPONT', 'thomas@example.test');
        $ecarte = $this->creerDirigeant('MARTIN', 'kevin@example.test');

        $client->request('POST', '/admin/effectif/dirigeants/envoyer-liens', [
            '_token' => $this->jeton($client),
            'dirigeants' => [(string) $garde->getUuid()],
        ]);

        self::assertCount(1, self::getMailerMessages());
        self::assertNotNull($this->relire($garde)->getLinkSentAt());
        self::assertNull($this->relire($ecarte)->getLinkSentAt());

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens');
        self::assertCount(1, $crawler->filter('.envoi-liens-item'), 'MARTIN est encore proposé');
        self::assertStringContainsString('MARTIN', $crawler->filter('.envoi-liens-item')->text());
    }

    /** Un uuid glissé dans le formulaire ne doit pas faire écrire à quelqu'un de non proposé. */
    public function testUnUuidEtrangerALaListeNeDeclencheAucunEnvoi(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dejaContacte = $this->creerDirigeant('DUPONT', 'thomas@example.test');
        $dejaContacte->setLinkSentAt(new \DateTimeImmutable('-3 days'));
        $this->em()->flush();

        $enAttente = $this->creerDirigeant('MARTIN', 'kevin@example.test');

        $client->request('POST', '/admin/effectif/dirigeants/envoyer-liens', [
            '_token' => $this->jeton($client),
            'dirigeants' => [(string) $enAttente->getUuid(), (string) $dejaContacte->getUuid()],
        ]);

        self::assertCount(1, self::getMailerMessages(), 'Seul le dirigeant réellement en attente est contacté');
    }

    /**
     * Le jeton est effacé dès le dossier complet : s'il servait de mémoire, un dirigeant
     * ayant tout signé réapparaîtrait ici comme jamais contacté.
     */
    public function testUnDossierCompletNeRevientPasDansLaListe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $signataire = $this->creerDirigeant('DUPONT', 'thomas@example.test');
        $signataire->setLinkSentAt(new \DateTimeImmutable('-20 days'));
        $signataire->setFormTokenExpiresAt(null);
        $signataire->setFormCompletedAt(new \DateTimeImmutable('-15 days'));
        $this->creerDirigeant('MARTIN', 'kevin@example.test');
        $this->em()->flush();

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens');

        self::assertCount(1, $crawler->filter('.envoi-liens-item'), 'Seul MARTIN est en attente');
        self::assertStringContainsString('MARTIN', $crawler->filter('.envoi-liens-item')->text());
    }

    public function testUnDirigeantSansEmailEstCompteAPartEtNonEnvoye(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->creerDirigeant('DUPONT', null);

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens');
        self::assertStringContainsString('sans adresse email', $crawler->filter('.envoi-liens-resume')->text());

        $client->submit($crawler->filter('.envoi-liens-form')->form());

        self::assertCount(0, self::getMailerMessages());

        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 sans adresse email', $crawler->filter('.flash-info')->text());
    }

    /**
     * Licence déclarée au district : elle ne remplit aucun formulaire. La proposer saison
     * après saison ferait d'un état normal un retard permanent — et un uuid forcé à la main
     * ne doit pas davantage déclencher de mail.
     */
    public function testUneLicenceAdministrativeNEstNiProposeeNiContactee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $president = $this->creerDirigeant('DUPONT', 'president@example.test');
        $president->setLicenceAdministrative(true);
        $this->creerDirigeant('MARTIN', 'kevin@example.test');
        $this->em()->flush();

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens');
        self::assertCount(1, $crawler->filter('.envoi-liens-item'), 'Seul MARTIN est proposé');

        $client->request('POST', '/admin/effectif/dirigeants/envoyer-liens', [
            '_token' => $this->jeton($client),
            'dirigeants' => [(string) $president->getUuid()],
        ]);

        self::assertCount(0, self::getMailerMessages());
        self::assertNull($this->relire($president)->getLinkSentAt());
    }

    /** Le bandeau de la liste est le seul chemin visible vers l'écran : il doit apparaître. */
    public function testLeBandeauAnnonceLesLiensEnAttenteSurLaListe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->creerDirigeant('DUPONT', 'thomas@example.test');

        $crawler = $client->request('GET', '/admin/effectif/dirigeants');

        self::assertStringContainsString('jamais reçu', $crawler->filter('.effectif-alerte')->first()->text());
    }

    private function relire(Dirigeant $dirigeant): Dirigeant
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Dirigeant::class)->findOneBy(['uuid' => $dirigeant->getUuid()]);
    }

    private function creerDirigeant(string $nom, ?string $email): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Thomas')
            ->setRole(DirigeantRole::DIRIGEANT)
            ->setSeason($this->courante);
        $dirigeant->setEmail($email);

        $this->em()->persist($dirigeant);
        $this->em()->flush();

        return $dirigeant;
    }

    private function formulaire(KernelBrowser $client): Form
    {
        return $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens')
            ->filter('.envoi-liens-form')
            ->form();
    }

    private function jeton(KernelBrowser $client): string
    {
        return $client->request('GET', '/admin/effectif/dirigeants/envoyer-liens')
            ->filter('.envoi-liens-form input[name=_token]')
            ->attr('value');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = $this->em();

        $this->courante = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-liens-dirigeants@example.test')->setPassword('x');
        $user->setSelectedSeason($this->courante);

        $em->persist($this->courante);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

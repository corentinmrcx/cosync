<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * Envoi groupé des liens d'inscription.
 *
 * L'import n'écrit plus à personne : cet écran est le seul point de départ des liens pour
 * un effectif entier. Ce qu'il ne doit jamais faire, c'est écrire à un licencié dont le
 * formulaire annoncerait un montant ou une dotation faux — d'où l'écart par défaut des
 * licenciés sans équipe.
 */
final class EnvoiLiensEnMasseTest extends WebTestCase
{
    private Season $courante;
    private Category $category;

    public function testLesLicenciesSansEquipeSontDecochesDOffice(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $equipe = $this->creerEquipe('U15 A');
        $avecEquipe = $this->creerLicencie('DUPONT', 'thomas@example.test', $equipe);
        $sansEquipe = $this->creerLicencie('MARTIN', 'kevin@example.test', null);

        $client->submit($this->formulaire($client));

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertCount(1, self::getMailerMessages(), 'Un seul destinataire : celui qui a une équipe');

        self::assertNotNull($this->relire($avecEquipe)->getLinkSentAt());
        self::assertSame(LicenceStatus::LINK_SENT, $this->relire($avecEquipe)->getDossierClub()?->getStatus());

        self::assertNull($this->relire($sansEquipe)->getLinkSentAt(), 'Le licencié sans équipe reste décoché');
        self::assertSame(LicenceStatus::IMPORTED, $this->relire($sansEquipe)->getDossierClub()?->getStatus());

        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 décoché', $crawler->filter('.flash-success')->text());
    }

    /** Décocher quelqu'un le met de côté sans le sortir de la liste : il repassera au prochain envoi. */
    public function testUnLicencieDecocheNEstPasContacteEtResteEnAttente(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $equipe = $this->creerEquipe('U15 A');
        $garde = $this->creerLicencie('DUPONT', 'thomas@example.test', $equipe);
        $ecarte = $this->creerLicencie('MARTIN', 'kevin@example.test', $equipe);

        $client->request('POST', '/admin/effectif/joueurs/envoyer-liens', [
            '_token' => $this->jeton($client),
            'licencies' => [(string) $garde->getUuid()],
        ]);

        self::assertCount(1, self::getMailerMessages());
        self::assertNotNull($this->relire($garde)->getLinkSentAt());
        self::assertNull($this->relire($ecarte)->getLinkSentAt());

        $crawler = $client->request('GET', '/admin/effectif/joueurs/envoyer-liens');
        self::assertCount(1, $crawler->filter('.envoi-liens-item'), 'MARTIN est encore proposé');
        self::assertStringContainsString('MARTIN', $crawler->filter('.envoi-liens-item')->text());
    }

    /** Un club qui n'affecte pas d'équipe doit pouvoir passer outre, en le décidant. */
    public function testUnLicencieSansEquipeCocheALaMainRecoitSonLien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $sansEquipe = $this->creerLicencie('MARTIN', 'kevin@example.test', null);

        $client->request('POST', '/admin/effectif/joueurs/envoyer-liens', [
            '_token' => $this->jeton($client),
            'licencies' => [(string) $sansEquipe->getUuid()],
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertCount(1, self::getMailerMessages());
        self::assertNotNull($this->relire($sansEquipe)->getLinkSentAt());
    }

    /** Un uuid glissé dans le formulaire ne doit pas faire écrire à quelqu'un de non proposé. */
    public function testUnUuidEtrangerALaListeNeDeclencheAucunEnvoi(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $equipe = $this->creerEquipe('U15 A');
        $dejaContacte = $this->creerLicencie('DUPONT', 'thomas@example.test', $equipe);
        $dejaContacte->setLinkSentAt(new \DateTimeImmutable('-3 days'));
        $this->em()->flush();

        $enAttente = $this->creerLicencie('MARTIN', 'kevin@example.test', $equipe);

        $client->request('POST', '/admin/effectif/joueurs/envoyer-liens', [
            '_token' => $this->jeton($client),
            'licencies' => [(string) $enAttente->getUuid(), (string) $dejaContacte->getUuid()],
        ]);

        self::assertCount(1, self::getMailerMessages(), 'Seul le licencié réellement en attente est contacté');
    }

    /** Un lien déjà envoyé ne se rejoue pas : ce serait un second mail pour rien. */
    public function testUnLicencieDejaContacteNEstPasRepris(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $equipe = $this->creerEquipe('U15 A');
        $envoiInitial = new \DateTimeImmutable('-3 days');
        $deja = $this->creerLicencie('DUPONT', 'thomas@example.test', $equipe);
        $deja->setLinkSentAt($envoiInitial);
        $this->em()->flush();

        $this->creerLicencie('MARTIN', 'kevin@example.test', $equipe);

        $crawler = $client->request('GET', '/admin/effectif/joueurs/envoyer-liens');
        self::assertCount(1, $crawler->filter('.envoi-liens-item'), 'Seul MARTIN est en attente');

        $client->submit($crawler->filter('.envoi-liens-form')->form());

        self::assertCount(1, self::getMailerMessages());
        self::assertSame(
            $envoiInitial->format('Y-m-d H:i:s'),
            $this->relire($deja)->getLinkSentAt()?->format('Y-m-d H:i:s'),
            'La date d\'envoi de DUPONT n\'a pas bougé',
        );
    }

    public function testUnLicencieSansEmailEstCompteAPartEtNonEnvoye(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $equipe = $this->creerEquipe('U15 A');
        $this->creerLicencie('DUPONT', null, $equipe);

        $crawler = $client->request('GET', '/admin/effectif/joueurs/envoyer-liens');
        self::assertStringContainsString('sans adresse email', $crawler->filter('.envoi-liens-resume')->text());

        $client->submit($crawler->filter('.envoi-liens-form')->form());

        self::assertCount(0, self::getMailerMessages());

        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 sans adresse email', $crawler->filter('.flash-info')->text());
    }

    /** Le bandeau de la liste est le seul chemin visible vers l'écran : il doit apparaître. */
    public function testLeBandeauAnnonceLesLiensEnAttenteSurLaListe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->creerLicencie('DUPONT', 'thomas@example.test', $this->creerEquipe('U15 A'));

        $crawler = $client->request('GET', '/admin/effectif/joueurs');

        self::assertStringContainsString('jamais reçu', $crawler->filter('.effectif-alerte')->first()->text());
    }

    private function relire(Licencie $licencie): Licencie
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Licencie::class)->findOneBy(['uuid' => $licencie->getUuid()]);
    }

    private function creerEquipe(string $nom): Team
    {
        $team = (new Team())->setName($nom)->setSeason($this->courante);
        $this->em()->persist($team);
        $this->em()->flush();

        return $team;
    }

    private function creerLicencie(string $nom, ?string $email, ?Team $team): Licencie
    {
        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2010-05-03'))
            ->setCategory($this->category)
            ->setSeason($this->courante);
        $licencie->setEmail($email);
        $licencie->setTeam($team);

        $dossier = new DossierClub();
        $dossier->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::IMPORTED);

        $this->em()->persist($licencie);
        $this->em()->persist($dossier);
        $this->em()->flush();

        return $licencie;
    }

    private function formulaire(KernelBrowser $client): Form
    {
        return $client->request('GET', '/admin/effectif/joueurs/envoyer-liens')
            ->filter('.envoi-liens-form')
            ->form();
    }

    private function jeton(KernelBrowser $client): string
    {
        return $client->request('GET', '/admin/effectif/joueurs/envoyer-liens')
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
        $this->category = (new Category())->setCode('U15')->setLabel('U15')->setIsEcoleFoot(false);
        $user = (new User())->setEmail('admin-liens@example.test')->setPassword('x');
        $user->setSelectedSeason($this->courante);

        $em->persist($this->courante);
        $em->persist($this->category);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

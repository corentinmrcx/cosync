<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CleMouvement;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CleMouvementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Écrans admin du club house : hub, registre des clés, édition du texte d'attestation,
 * plus les mutations (mouvement de clé, envoi du lien de signature).
 */
final class ClubHouseScreensTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLesTroisEcransRepondent(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        foreach (['/admin/club-house', '/admin/club-house/cles', '/admin/club-house/attestation'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('L\'écran %s doit répondre.', $url));
        }
    }

    public function testLeRegistreAfficheLesDetenteursEtLeursCles(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant('DUPONT', 'Thomas');
        $this->makeMouvement($dirigeant, CleMouvementType::REMISE, 2, '2026-01-10');

        $crawler = $client->request('GET', '/admin/club-house/cles');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DUPONT Thomas', $crawler->html());
        self::assertStringContainsString('10/01/2026', $crawler->html());
        self::assertStringContainsString('Non signée', $crawler->html());
    }

    public function testUnMouvementValideEstEnregistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant();
        $crawler = $client->request('GET', '/admin/club-house/cles');
        $token = $crawler->filter('form[action$="cles/mouvement"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/club-house/cles/mouvement', [
            '_token' => $token,
            'dirigeant' => (string) $dirigeant->getUuid(),
            'type' => 'remise',
            'quantite' => '2',
            'date_mouvement' => '2026-03-15',
        ]);

        self::assertResponseRedirects('/admin/club-house/cles');
        self::assertSame(2, $this->soldeDe($dirigeant));
    }

    public function testUneRestitutionImpossibleAfficheUneErreurSansRienEnregistrer(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant();
        $this->makeMouvement($dirigeant, CleMouvementType::REMISE, 1, '2026-01-10');

        $crawler = $client->request('GET', '/admin/club-house/cles');
        $token = $crawler->filter('form[action$="cles/mouvement"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/club-house/cles/mouvement', [
            '_token' => $token,
            'dirigeant' => (string) $dirigeant->getUuid(),
            'type' => 'restitution',
            'quantite' => '5',
            'date_mouvement' => '2026-03-15',
        ]);

        self::assertResponseRedirects('/admin/club-house/cles');
        self::assertSame(1, $this->soldeDe($dirigeant), 'Le solde ne doit pas bouger.');
    }

    public function testUnJetonCsrfInvalideNEnregistreAucunMouvement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant();

        $client->request('POST', '/admin/club-house/cles/mouvement', [
            '_token' => 'jeton-bidon',
            'dirigeant' => (string) $dirigeant->getUuid(),
            'type' => 'remise',
            'quantite' => '1',
        ], [], ['HTTP_REFERER' => '/admin/club-house/cles']);

        self::assertResponseRedirects('/admin/club-house/cles');
        self::assertSame(0, $this->soldeDe($dirigeant));
    }

    public function testLEnvoiDuLienPositionneLeTokenDAttestation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant();
        $this->makeMouvement($dirigeant, CleMouvementType::REMISE, 1, '2026-01-10');
        $uuid = (string) $dirigeant->getUuid();

        $crawler = $client->request('GET', '/admin/club-house/cles');
        $token = $crawler->filter('form[action$="envoyer-lien"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/club-house/cles/' . $uuid . '/attestation/envoyer-lien', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/club-house/cles');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $rechargé = $em->find(Dirigeant::class, Uuid::fromString($uuid));

        self::assertTrue($rechargé->isAttestationCleTokenValid());
        self::assertNull($rechargé->getFormCompletedAt(), 'Le dossier dirigeant n\'est pas touché.');
    }

    public function testLeRecapitulatifEstUnPdfTelechargeable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/club-house/attestation/recapitulatif');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $client->getResponse()->getContent());
    }

    public function testLeTexteDAttestationEstEnregistreSurLaSaisonCourante(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club-house/attestation');
        // Cibler le formulaire d'édition : le premier _token de la page est celui
        // du sélecteur de saison de la navbar.
        $token = $crawler->filter('#attestation-form input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/club-house/attestation', [
            '_token' => $token,
            'attestation_cle_text' => '<p>La commune met le local à disposition.</p>',
        ]);

        self::assertResponseRedirects('/admin/club-house/attestation');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $season = $em->find(Season::class, $this->season->getId());

        self::assertStringContainsString('met le local à disposition', (string) $season->getAttestationCleText());
    }

    public function testLesEcransSontInaccessiblesSansAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/club-house');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /* ── Fabriques ── */

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-clubhouse@example.com')->setPassword('x');

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function makeDirigeant(string $nom = 'MARTIN', string $prenom = 'Kevin'): Dirigeant
    {
        static $n = 0;
        ++$n;

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('clubhouse%d@example.com', $n))
            ->setSeason($this->season);

        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
    }

    private function makeMouvement(Dirigeant $dirigeant, CleMouvementType $type, int $quantite, string $date): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $mouvement = (new CleMouvement())
            ->setDirigeant($dirigeant)
            ->setSeason($dirigeant->getSeason())
            ->setType($type)
            ->setQuantite($quantite)
            ->setDateMouvement(new \DateTimeImmutable($date));

        $em->persist($mouvement);
        $em->flush();
    }

    private function soldeDe(Dirigeant $dirigeant): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(CleMouvement::class)->getSolde($dirigeant);
    }
}

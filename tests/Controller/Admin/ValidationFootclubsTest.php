<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Service\Licencie\PaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le dernier geste du parcours : déclarer qu'une licence a été signée dans FootClubs.
 *
 * Ce geste n'a aucun automatisme possible — CoSync ne parle pas à FootClubs. Tout ce que
 * ces tests protègent tient à ça : le solde n'est pas la validation, la validation ne
 * s'obtient que sur décision d'un admin, et elle se défait en cas de clic malheureux.
 */
final class ValidationFootclubsTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const COTISATION = 85;

    public function testValiderDepuisLaFichePasseLaLicenceEnValidee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(LicenceStatus::A_VALIDER_FFF);

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/valider-footclubs', [
            '_token' => $this->token($client, $uuid, '/valider-footclubs'),
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $uuid);
        self::assertSame(LicenceStatus::VALIDATED, $this->reloadDossier($uuid)->getStatus());
        self::assertCount(0, self::getMailerMessages(), 'Démarche interne au club : le licencié a déjà été prévenu au solde');
    }

    /** Le bouton n'est pas offert avant le solde, et le service refuse tout autant. */
    public function testUneLicenceNonSoldeeNeSeValidePas(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(LicenceStatus::FORM_COMPLETED);

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);
        self::assertCount(0, $crawler->filter('form[action$="/valider-footclubs"]'));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $licencie = $em->find(Licencie::class, Uuid::fromString($uuid));

        $this->expectException(\DomainException::class);
        self::getContainer()->get(PaiementService::class)->validerSurFootclubs($licencie);
    }

    /** Sans retour en arrière, un clic de trop ferait disparaître une licence à signer. */
    public function testLaValidationSAnnule(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(LicenceStatus::VALIDATED);

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/annuler-validation-footclubs', [
            '_token' => $this->token($client, $uuid, '/annuler-validation-footclubs'),
        ]);

        self::assertSame(LicenceStatus::A_VALIDER_FFF, $this->reloadDossier($uuid)->getStatus());
    }

    /**
     * L'écran groupé ne valide que ce qui était proposé : un uuid glissé dans le formulaire
     * posté ne doit pas pouvoir valider une licence qui n'y figurait pas.
     */
    public function testLEcranGroupeNeValideQueLesLicencesProposees(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $aValider = $this->seedLicencie(LicenceStatus::A_VALIDER_FFF);
        $nonProposee = $this->seedLicencie(LicenceStatus::FORM_COMPLETED, email: 'autre@example.test');

        $crawler = $client->request('GET', '/admin/effectif/joueurs/valider-footclubs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="personnes[]"]'));

        $client->request('POST', '/admin/effectif/joueurs/valider-footclubs', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'personnes' => [$aValider, $nonProposee],
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs');
        self::assertSame(LicenceStatus::VALIDATED, $this->reloadDossier($aValider)->getStatus());
        self::assertSame(LicenceStatus::FORM_COMPLETED, $this->reloadDossier($nonProposee)->getStatus());
    }

    public function testLaListeAnnonceLesLicencesAValider(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->seedLicencie(LicenceStatus::A_VALIDER_FFF);

        $crawler = $client->request('GET', '/admin/effectif/joueurs');

        self::assertStringContainsString('à valider sur FootClubs', $crawler->filter('.effectif-alertes')->text());
        self::assertStringContainsString('À valider sur FootClubs', $crawler->filter('.licencies-col-status')->last()->text());
    }

    /* ── Dirigeants ── */

    /**
     * Sur une licence administrative : elle n'attend ni lien ni document, mais elle existe
     * à la FFF — c'est justement l'état où la validation est le seul geste qui reste.
     */
    public function testUnDirigeantSeValideEtSAnnule(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedDirigeant(licenceAdministrative: true);

        $client->request('POST', '/admin/effectif/dirigeants/' . $uuid . '/valider-footclubs', [
            '_token' => $this->tokenDirigeant($client, $uuid, '/valider-footclubs'),
        ]);

        self::assertNotNull($this->reloadDirigeant($uuid)->getValidatedFffAt());

        $client->request('POST', '/admin/effectif/dirigeants/' . $uuid . '/annuler-validation-footclubs', [
            '_token' => $this->tokenDirigeant($client, $uuid, '/annuler-validation-footclubs'),
        ]);

        self::assertNull($this->reloadDirigeant($uuid)->getValidatedFffAt());
    }

    public function testLEcranGroupeDesDirigeantsValideLesDossiersComplets(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $complet = $this->seedDirigeant(renseigne: true);
        $jamaisContacte = $this->seedDirigeant();

        $crawler = $client->request('GET', '/admin/effectif/dirigeants/valider-footclubs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="personnes[]"]'), 'Seul le dossier complet est proposé.');

        $client->request('POST', '/admin/effectif/dirigeants/valider-footclubs', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'personnes' => [$complet, $jamaisContacte],
        ]);

        self::assertNotNull($this->reloadDirigeant($complet)->getValidatedFffAt());
        self::assertNull($this->reloadDirigeant($jamaisContacte)->getValidatedFffAt());
    }

    /** Le besoin d'origine : lire l'avancement sans ouvrir chaque fiche. */
    public function testLaListeDesDirigeantsAfficheLeStatut(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->seedDirigeant();

        $crawler = $client->request('GET', '/admin/effectif/dirigeants');

        self::assertStringContainsString('Lien non envoyé', $crawler->filter('.dirigeants-col-status')->last()->text());
    }

    /* ── Outils ── */

    private function token(KernelBrowser $client, string $uuid, string $actionSuffixe): string
    {
        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);
        $champ = $crawler->filter('form[action$="' . $actionSuffixe . '"] input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), sprintf('Formulaire %s introuvable sur la fiche.', $actionSuffixe));

        return (string) $champ->first()->attr('value');
    }

    private function tokenDirigeant(KernelBrowser $client, string $uuid, string $actionSuffixe): string
    {
        $crawler = $client->request('GET', '/admin/effectif/dirigeants/' . $uuid);
        $champ = $crawler->filter('form[action$="' . $actionSuffixe . '"] input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), sprintf('Formulaire %s introuvable sur la fiche.', $actionSuffixe));

        return (string) $champ->first()->attr('value');
    }

    private function reloadDossier(string $uuid): DossierClub
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Licencie::class, Uuid::fromString($uuid))->getDossierClub();
    }

    private function reloadDirigeant(string $uuid): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Dirigeant::class, Uuid::fromString($uuid));
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-fff@example.test')->setPassword('x');

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }

    private function seedLicencie(LicenceStatus $statut, string $email = 'kevin.martin@example.test'): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($this->category($em))
            ->setSeason($this->season($em));

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($statut)
            ->setFormCompletedAt(new \DateTimeImmutable());

        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }

    private function seedDirigeant(bool $licenceAdministrative = false, bool $renseigne = false): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $dirigeant = (new Dirigeant())
            ->setNom('DURAND')
            ->setPrenom('Marie')
            ->setLicenceAdministrative($licenceAdministrative)
            ->setSeason($this->season($em));

        if ($renseigne) {
            $dirigeant->setVolontaireTransport(false)
                ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
                ->setAutorisationPhoto(true)
                ->setFormCompletedAt(new \DateTimeImmutable());
        }

        $em->persist($dirigeant);
        $em->flush();

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        return $uuid;
    }

    private function season(EntityManagerInterface $em): Season
    {
        $season = $em->getRepository(Season::class)->findOneBy(['label' => '2025-2026'])
            ?? (new Season())->setLabel('2025-2026')->setCotisationDefaut(self::COTISATION);
        $em->persist($season);

        return $season;
    }

    private function category(EntityManagerInterface $em): Category
    {
        $category = $em->getRepository(Category::class)->findOneBy(['code' => 'SENIOR'])
            ?? (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $em->persist($category);

        return $category;
    }
}

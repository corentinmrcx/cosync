<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Confirmation des paiements hors ligne depuis l'admin (chèque, espèces, virement…).
 *
 * C'est par là que passe la majorité des cotisations : le pendant HelloAsso est
 * couvert par HelloAssoPaymentRecorderTest, celui-ci couvre la saisie manuelle,
 * le passage automatique en licence validée et le mail qui en découle.
 */
final class PaiementAdminTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const COTISATION = 85;

    public function testUnPaiementSaisiEstEnregistreAvecTousSesChamps(): void
    {
        $client = static::createClient();
        $admin = $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/ajouter-paiement', [
            '_token' => $this->token($client, $uuid, '/ajouter-paiement'),
            'mode' => 'cheque',
            'montant' => '40,50',
            'date_paiement' => '2026-03-15',
            'reference' => 'Chèque n°123456',
            'note' => 'Remis au local',
        ]);

        self::assertResponseRedirects();

        $transaction = $this->transactionsDe($uuid)[0];

        self::assertSame(PaymentMode::CHEQUE, $transaction->getMode());
        self::assertEqualsWithDelta(40.50, $transaction->getMontant(), 0.001, 'La virgule décimale doit être acceptée');
        self::assertSame('Chèque n°123456', $transaction->getReference());
        self::assertSame('Remis au local', $transaction->getNote());
        self::assertSame('2026-03-15', $transaction->getDatePaiement()->format('Y-m-d'));
        self::assertSame($admin->getEmail(), $transaction->getConfirmedBy()?->getEmail(), 'Le paiement doit tracer l\'admin qui l\'a confirmé');
    }

    /** Un paiement partiel laisse la licence en attente : c'est tout l'intérêt du suivi. */
    public function testUnPaiementPartielNeValidePasLaLicence(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $this->payer($client, $uuid, '40');

        self::assertSame(LicenceStatus::FORM_COMPLETED, $this->reloadDossier($uuid)->getStatus());
        self::assertCount(0, self::getMailerMessages(), 'Aucun mail de validation tant que le compte n\'y est pas');
    }

    public function testLeSoldeCompletMarqueLaLicenceAValiderEtEnvoieLeMail(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $this->payer($client, $uuid, '40');
        $this->payer($client, $uuid, '45');

        // Le solde ne valide pas la licence : il la met « à valider sur FootClubs ». La
        // validation, elle, se déclare à la main une fois la démarche fédérale faite.
        self::assertSame(LicenceStatus::A_VALIDER_FFF, $this->reloadDossier($uuid)->getStatus());

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages, 'Un seul mail de validation, à l\'atteinte du solde');
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $messages[0]);
        self::assertSame('kevin.martin@example.test', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('validée', $messages[0]->getSubject());
    }

    /** Un trop-perçu vaut solde atteint : le dossier avance, il n'est pas bloqué. */
    public function testUnPaiementSuperieurALaCotisationSoldeAussi(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $this->payer($client, $uuid, '100');

        self::assertSame(LicenceStatus::A_VALIDER_FFF, $this->reloadDossier($uuid)->getStatus());
    }

    public function testUnMontantNulOuNegatifEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        foreach (['0', '-10'] as $montant) {
            $this->payer($client, $uuid, $montant);
        }

        self::assertCount(0, $this->transactionsDe($uuid));
        self::assertSame(LicenceStatus::FORM_COMPLETED, $this->reloadDossier($uuid)->getStatus());
    }

    public function testUnModeDePaiementInconnuEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/ajouter-paiement', [
            '_token' => $this->token($client, $uuid, '/ajouter-paiement'),
            'mode' => 'bitcoin',
            'montant' => '85',
            'date_paiement' => '2026-03-15',
        ]);

        self::assertCount(0, $this->transactionsDe($uuid));
    }

    /**
     * Le club n'encaisse plus les chèques CAF ni les chèques ANCV : ils ne doivent plus être
     * proposés à la saisie. Les modes restent déclarés dans l'enum pour relire les paiements
     * déjà enregistrés — c'est bien la liste offerte qui doit s'être resserrée.
     */
    public function testLaModaleNeProposeQueLesModesEncoreAcceptes(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);
        $modes = $crawler->filter('form[action$="/ajouter-paiement"] select[name="mode"] option')
            ->each(fn (Crawler $option) => (string) $option->attr('value'));

        self::assertSame(['cb_online', 'virement', 'cheque', 'especes', 'pass_sport', 'autre'], $modes);
    }

    public function testUnJetonCsrfInvalideRefuseLaSaisie(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/ajouter-paiement', [
            '_token' => 'jeton-bidon',
            'mode' => 'especes',
            'montant' => '85',
            'date_paiement' => '2026-03-15',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->transactionsDe($uuid));
    }

    /**
     * Chaque compte admin travaille dans la saison de son choix, et une fiche s'ouvre par
     * UUID sans passer par la liste filtrée. Un dirigeant resté sur une saison passée doit
     * malgré tout rattacher son encaissement à la saison du licencié : sinon le solde est
     * calculé sur le mauvais exercice, la licence ne passe jamais en validée, et le
     * paiement reste invisible sur la fiche — le club a l'argent, l'app dit le contraire.
     */
    public function testUnAdminSurUneAutreSaisonRattacheLePaiementACelleDuLicencie(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client, saisonSelectionnee: '2024-2025');
        $uuid = $this->seedLicencie();

        $this->payer($client, $uuid, '85');

        $transaction = $this->transactionsDe($uuid)[0];

        self::assertSame('2025-2026', $transaction->getSeason()->getLabel());
        self::assertSame(LicenceStatus::A_VALIDER_FFF, $this->reloadDossier($uuid)->getStatus());
    }

    /* ── Suppression ── */

    public function testUnPaiementSaisiParErreurPeutEtreSupprime(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $this->payer($client, $uuid, '40');
        $id = $this->transactionsDe($uuid)[0]->getId();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/paiements/' . $id . '/supprimer', [
            '_token' => $this->token($client, $uuid, '/paiements/' . $id . '/supprimer'),
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->transactionsDe($uuid));
    }

    /** Un paiement ne peut être supprimé que depuis la fiche de son propre licencié. */
    public function testUnPaiementNAppartenantPasAuLicencieEstIntrouvable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();
        $autre = $this->seedLicencie(email: 'autre@example.test');

        $this->payer($client, $uuid, '40');
        $id = $this->transactionsDe($uuid)[0]->getId();

        $client->request('POST', '/admin/effectif/joueurs/' . $autre . '/paiements/' . $id . '/supprimer', [
            '_token' => $this->token($client, $uuid, '/paiements/' . $id . '/supprimer'),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->transactionsDe($uuid));
    }

    /* ── Validation manuelle ── */

    public function testLaValidationManuelleSoldeSansPaiement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/valider-manuellement', [
            '_token' => $this->token($client, $uuid, '/valider-manuellement'),
        ]);

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $uuid);
        // « Valider quand même » court-circuite le paiement, pas la démarche FFF.
        self::assertSame(LicenceStatus::A_VALIDER_FFF, $this->reloadDossier($uuid)->getStatus());
        self::assertCount(0, $this->transactionsDe($uuid), 'Valider ne crée aucune transaction fictive');
        self::assertCount(1, self::getMailerMessages());
    }

    /* ── Accès ── */

    public function testLesRoutesDePaiementExigentUneAuthentification(): void
    {
        $client = static::createClient();
        $uuid = $this->seedLicencie();

        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/valider-manuellement', ['_token' => 'x']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(LicenceStatus::FORM_COMPLETED, $this->reloadDossier($uuid)->getStatus());
    }

    /* ── Outils ── */

    private function payer(KernelBrowser $client, string $uuid, string $montant): void
    {
        $client->request('POST', '/admin/effectif/joueurs/' . $uuid . '/ajouter-paiement', [
            '_token' => $this->token($client, $uuid, '/ajouter-paiement'),
            'mode' => 'especes',
            'montant' => $montant,
            'date_paiement' => '2026-03-15',
        ]);
    }

    /**
     * Jeton repris de la fiche réellement rendue : le gestionnaire CSRF stocke les jetons
     * en session, inaccessible hors requête. C'est aussi ce que fait un vrai navigateur.
     */
    private function token(KernelBrowser $client, string $uuid, string $actionSuffixe): string
    {
        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);
        $champ = $crawler->filter('form[action$="' . $actionSuffixe . '"] input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), sprintf('Formulaire %s introuvable sur la fiche.', $actionSuffixe));

        return (string) $champ->first()->attr('value');
    }

    /** @return Transaction[] */
    private function transactionsDe(string $uuid): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Transaction::class)->findBy(
            ['licencie' => $em->find(Licencie::class, Uuid::fromString($uuid))],
            ['id' => 'ASC'],
        );
    }

    private function reloadDossier(string $uuid): DossierClub
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Licencie::class, Uuid::fromString($uuid))->getDossierClub();
    }

    private function loginAdmin(KernelBrowser $client, ?string $saisonSelectionnee = null): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-paiement@example.test')->setPassword('x');

        if ($saisonSelectionnee !== null) {
            $season = $em->getRepository(Season::class)->findOneBy(['label' => $saisonSelectionnee])
                ?? (new Season())->setLabel($saisonSelectionnee)->setCotisationDefaut(self::COTISATION);
            $em->persist($season);
            $user->setSelectedSeason($season);
        }

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }

    /** Dossier au stade « formulaire rempli, paiement attendu » — le cas réel de la saisie admin. */
    private function seedLicencie(string $email = 'kevin.martin@example.test'): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = $em->getRepository(Season::class)->findOneBy(['label' => '2025-2026'])
            ?? (new Season())->setLabel('2025-2026')->setCotisationDefaut(self::COTISATION);
        $category = $em->getRepository(Category::class)->findOneBy(['code' => 'SENIOR'])
            ?? (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable());

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }
}

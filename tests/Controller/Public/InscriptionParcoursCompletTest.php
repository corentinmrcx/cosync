<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours public d'inscription, de bout en bout.
 *
 * Ce que ces tests protègent : **ce que le licencié saisit est bien ce qui arrive en base**.
 * Les valeurs postées sont volontairement toutes différentes les unes des autres — une
 * inversion taille de bas / pointure, ou une autorisation transport dirigeants recopiée
 * dans l'autorisation parents, doit faire échouer un test, pas passer inaperçue.
 */
final class InscriptionParcoursCompletTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** 1×1 px transparent : la plus petite signature valide possible. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /* ── Sénior : persistance champ par champ ── */

    public function testChaqueChampDuFormulaireSeniorArriveEnBase(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'taille_haut' => 'XL',
            'taille_bas' => 'S',
            'pointure' => '44',
            'autorisation_photo' => '0',
            'payment_intention' => 'cheque',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');

        $dossier = $this->reloadDossier($uuid);

        self::assertSame('XL', $dossier->getTailleHaut(), 'La taille de haut postée doit être celle stockée');
        self::assertSame('S', $dossier->getTailleBas(), 'La taille de bas postée doit être celle stockée');
        self::assertSame('44', $dossier->getPointure(), 'La pointure postée doit être celle stockée');
        self::assertFalse($dossier->getAutorisationPhoto(), 'Un refus du droit à l\'image doit être enregistré comme un refus');
        self::assertSame([PaymentMode::CHEQUE], $dossier->getPaymentIntentions());
        self::assertNotNull($dossier->getFormCompletedAt());
        self::assertSame(LicenceStatus::FORM_COMPLETED, $dossier->getStatus());
    }

    /** Un sénior n'a aucune question d'autorisation transport : les champs restent vides. */
    public function testUnSeniorNaPasDAutorisationsDeTransport(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid));

        $dossier = $this->reloadDossier($uuid);

        self::assertNull($dossier->getAutorisationTransportDirigeants());
        self::assertNull($dossier->getAutorisationTransportParents());
        self::assertNull($dossier->getAutorisationAccident());
        self::assertNull($dossier->getVolontaireTransport());
        self::assertNull($dossier->getAttestationTransportDriveId());
    }

    public function testLeMultiPaiementEnregistreTousLesModesChoisis(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $payload = $this->payloadSenior($client, $uuid, [
            'multi_payment' => '1',
            'payment_intentions' => ['especes', 'pass_sport'],
        ]);
        unset($payload['payment_intention']);

        $client->request('POST', '/inscription/' . $uuid, $payload);

        self::assertSame(
            [PaymentMode::ESPECES, PaymentMode::PASS_SPORT],
            $this->reloadDossier($uuid)->getPaymentIntentions(),
        );
    }

    /**
     * Le bouton « payer par carte » vaut choix du mode : aucun radio n'est coché côté client.
     * L'inscription doit être enregistrée AVANT la redirection, pour que le licencié ne
     * perde rien s'il abandonne sur HelloAsso.
     */
    public function testLePaiementParCarteEnregistreLInscriptionAvantDeRediriger(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $payload = $this->payloadSenior($client, $uuid, ['pay_online' => '1']);
        unset($payload['payment_intention']);

        $client->request('POST', '/inscription/' . $uuid, $payload);

        self::assertResponseRedirects('/inscription/' . $uuid . '/paiement/demarrer');

        $dossier = $this->reloadDossier($uuid);
        self::assertNotNull($dossier->getFormCompletedAt(), 'Le dossier doit être enregistré avant le départ vers HelloAsso');
        self::assertSame([PaymentMode::CB_ONLINE], $dossier->getPaymentIntentions());
    }

    public function testUnModeDePaiementInconnuRejetteLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'payment_intention' => 'bitcoin',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    public function testUnJetonCsrfInvalideRejetteLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            '_token' => 'jeton-bidon',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    /* ── Jeune : autorisations + attestation transport ── */

    public function testLesQuatreAutorisationsDUnJeuneSontEnregistreesTellesQuePostees(): void
    {
        $client = static::createClient();
        $uuid = $this->seedJeune();

        // Quatre réponses volontairement dissemblables : une permutation doit se voir.
        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'autorisation_photo' => '1',
            'autorisation_accident' => '0',
            'autorisation_transport_dirigeants' => '1',
            'autorisation_transport_parents' => '0',
            'volontaire_transport' => '0',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');

        $dossier = $this->reloadDossier($uuid);

        self::assertTrue($dossier->getAutorisationPhoto());
        self::assertFalse($dossier->getAutorisationAccident());
        self::assertTrue($dossier->getAutorisationTransportDirigeants());
        self::assertFalse($dossier->getAutorisationTransportParents());
        self::assertFalse($dossier->getVolontaireTransport());
        self::assertNull($dossier->getAttestationTransportDriveId(), 'Pas d\'attestation sans volontariat');
    }

    public function testUnJeuneVolontaireAuTransportGenereSonAttestation(): void
    {
        $client = static::createClient();
        $uuid = $this->seedJeune();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'autorisation_photo' => '1',
            'autorisation_accident' => '1',
            'autorisation_transport_dirigeants' => '1',
            'autorisation_transport_parents' => '1',
            'volontaire_transport' => '1',
            'attestation_nom_conducteur' => 'DUPONT',
            'attestation_prenom_conducteur' => 'Claire',
            'attestation_num_permis' => '123456789',
            'attestation_assurance' => 'MAIF, 12 rue des Sports',
            'attestation_date_ct' => '2025-06-01',
            'attestation_engagement' => '1',
            'attestation_signature_data' => self::SIGNATURE,
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');

        $dossier = $this->reloadDossier($uuid);
        self::assertTrue($dossier->getVolontaireTransport());

        $chemin = $dossier->getAttestationTransportDriveId();
        self::assertNotNull($chemin, 'L\'attestation de transport doit avoir été générée');
        // Tant que l'upload Drive n'a pas eu lieu, la valeur est un chemin local absolu.
        self::assertStringStartsWith('/', $chemin);
        self::assertFileExists($chemin);

        @unlink($chemin);
    }

    /** Chez un jeune, les autorisations sont obligatoires : une réponse absente rejette tout. */
    public function testUneAutorisationManquanteChezUnJeuneRejetteLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedJeune();

        $payload = $this->payloadSenior($client, $uuid, [
            'autorisation_accident' => '1',
            'autorisation_transport_dirigeants' => '1',
            'volontaire_transport' => '0',
        ]);
        // autorisation_transport_parents jamais postée

        $client->request('POST', '/inscription/' . $uuid, $payload);

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    public function testUnVolontaireSansAttestationValideRejetteLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedJeune();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'autorisation_accident' => '1',
            'autorisation_transport_dirigeants' => '1',
            'autorisation_transport_parents' => '1',
            'volontaire_transport' => '1',
            // aucun champ attestation_*
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    /* ── Cycle de vie du lien ── */

    public function testLeLienEstConsommeParLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $licencie = $em->find(Licencie::class, Uuid::fromString($uuid));

        self::assertNull($licencie->getFormTokenExpiresAt());
        self::assertFalse($licencie->isFormTokenValid());
    }

    public function testUnDeuxiemeAccesRedirigeVersLaConfirmation(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid));
        $client->request('GET', '/inscription/' . $uuid);

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');
    }

    /** Rejouer la soumission ne doit pas réécrire un dossier déjà rempli. */
    public function testUneDeuxiemeSoumissionEstRefusee(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();
        $payload = $this->payloadSenior($client, $uuid, ['taille_haut' => 'XL']);

        $client->request('POST', '/inscription/' . $uuid, $payload);
        $premiere = $this->reloadDossier($uuid)->getFormCompletedAt();

        $client->request('POST', '/inscription/' . $uuid, array_merge($payload, ['taille_haut' => 'XS']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'lien');

        $dossier = $this->reloadDossier($uuid);
        self::assertSame('XL', $dossier->getTailleHaut(), 'La seconde soumission ne doit rien écraser');
        self::assertEquals($premiere, $dossier->getFormCompletedAt());
    }

    public function testUnLienExpireNAfficheAucunFormulaire(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior(expiration: new \DateTimeImmutable('-1 day'));

        $crawler = $client->request('GET', '/inscription/' . $uuid);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="taille_haut"]'));
    }

    public function testUnLienExpireRefuseLaSoumission(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior(expiration: new \DateTimeImmutable('-1 day'));

        $client->request('POST', '/inscription/' . $uuid, [
            '_token' => 'peu-importe',
            'taille_haut' => 'L',
            'taille_bas' => 'M',
            'pointure' => '42',
            'autorisation_photo' => '1',
            'payment_intention' => 'especes',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    /* ── Accusé de réception ── */

    public function testLaSoumissionEnvoieUnAccuseDeReceptionAuLicencie(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid));

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $messages[0]);
        self::assertSame('kevin.martin@example.test', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Inscription bien reçue', $messages[0]->getSubject());
    }

    public function testUnLicencieSansEmailSoumetQuandMemeSonDossier(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior(email: null);

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid));

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');
        self::assertNotNull($this->reloadDossier($uuid)->getFormCompletedAt());
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * Le test le plus important de cette série : le dossier est déjà enregistré et les
     * signatures prises quand le mail part. Une panne de SMTP ne doit jamais faire perdre
     * une inscription ni afficher une erreur au licencié.
     */
    public function testUnEchecDEnvoiNeFaitPasPerdreLInscription(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        // Transport en panne : toute tentative d'envoi lève.
        self::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP injoignable');
            }
        });

        $client->request('POST', '/inscription/' . $uuid, $this->payloadSenior($client, $uuid, [
            'taille_haut' => 'XL',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');

        $dossier = $this->reloadDossier($uuid);
        self::assertNotNull($dossier->getFormCompletedAt());
        self::assertSame(LicenceStatus::FORM_COMPLETED, $dossier->getStatus());
        self::assertSame('XL', $dossier->getTailleHaut());
    }

    /* ── Coordonnées bancaires ── */

    public function testLOptionVirementDisparaitQuandLaSaisonNaPasDIban(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior();

        $html = (string) $client->request('GET', '/inscription/' . $uuid)->html();

        self::assertStringNotContainsString('Virement bancaire', $html, 'Proposer un virement sans RIB envoie le licencié dans le mur');
    }

    public function testLeFormulaireAfficheLIbanDeLaSaison(): void
    {
        $client = static::createClient();
        $uuid = $this->seedSenior(iban: 'FR76 3000 4000 0300 0000 0000 143');

        $html = (string) $client->request('GET', '/inscription/' . $uuid)->html();

        self::assertStringContainsString('Virement bancaire', $html);
        self::assertStringContainsString('FR76 3000 4000 0300 0000 0000 143', $html);
        self::assertStringContainsString('COTISATION MARTIN Kevin', $html);
    }

    /** Un UUID malformé doit donner un 404, pas une erreur 500 sur Uuid::fromString(). */
    public function testUnUuidMalformeDonneUn404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/inscription/pas-un-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    /* ── Fixtures ── */

    /**
     * Champs obligatoires d'un sénior. Le jeton CSRF est repris de la page réelle : sans lui,
     * les tests de rejet passeraient pour la mauvaise raison.
     *
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payloadSenior(KernelBrowser $client, string $uuid, array $extra = []): array
    {
        $crawler = $client->request('GET', '/inscription/' . $uuid);
        $champ = $crawler->filter('input[name="_token"]');

        return array_merge([
            '_token' => $champ->count() > 0 ? $champ->attr('value') : '',
            'taille_haut' => 'L',
            'taille_bas' => 'M',
            'pointure' => '42',
            'autorisation_photo' => '1',
            'payment_intention' => 'especes',
        ], $extra);
    }

    private function reloadDossier(string $uuid): DossierClub
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Licencie::class, Uuid::fromString($uuid))->getDossierClub();
    }

    private function seedSenior(
        ?\DateTimeImmutable $expiration = null,
        ?string $email = 'kevin.martin@example.test',
        ?string $iban = null,
    ): string {
        return $this->seed('SENIOR', 'Séniors', new \DateTimeImmutable('1995-01-01'), $expiration, $email, $iban);
    }

    /** U11 : Category::isJeune() se déclenche sur le préfixe « U », d'où les autorisations. */
    private function seedJeune(?\DateTimeImmutable $expiration = null): string
    {
        return $this->seed('U11', 'U11', new \DateTimeImmutable('2015-04-12'), $expiration);
    }

    private function seed(
        string $code,
        string $label,
        \DateTimeImmutable $naissance,
        ?\DateTimeImmutable $expiration,
        ?string $email = 'kevin.martin@example.test',
        ?string $iban = null,
    ): string {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85)->setIban($iban);
        $category = (new Category())->setCode($code)->setLabel($label)->setIsEcoleFoot($code !== 'SENIOR');

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance($naissance)
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season)
            ->setFormTokenExpiresAt($expiration ?? new \DateTimeImmutable('+30 days'));

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::LINK_SENT);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }
}

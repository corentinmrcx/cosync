<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AttestationPaiement;
use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Civilite;
use App\Enum\PaymentMode;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Le parcours complet : depuis la fiche du licencié jusqu'au PDF archivé.
 *
 * L'écran ne s'ouvre que sur une licence soldée — c'est ici que se vérifie que le verrou
 * tient aussi côté HTTP, et pas seulement dans le service.
 */
final class AttestationPaiementEcranTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testLaFicheProposeLeBoutonQuandLaLicenceEstSoldee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/admin/attestations-paiement/nouvelle/' . $licencie->getUuid() . '"]')->count(),
        );
    }

    public function testLaFicheNeProposeRienSansPaiement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencie();

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('a[href="/admin/attestations-paiement/nouvelle/' . $licencie->getUuid() . '"]')->count(),
        );
    }

    /** Le verrou du service doit aussi fermer la porte à qui arrive par l'URL. */
    public function testLEcranRefuseUneLicenceNonSoldee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencie();
        $this->paiement($licencie, '60.00');

        $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $licencie->getUuid());
    }

    public function testLEcranNeProposeNiMontantNiDateNiMode(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());

        self::assertResponseIsSuccessful();
        // Le fait comptable est affiché, jamais saisi.
        self::assertSame(0, $crawler->filter('input[name="attestation_paiement[montant]"]')->count());
        self::assertSame(0, $crawler->filter('input[name="attestation_paiement[datePaiement]"]')->count());
        self::assertSame(0, $crawler->filter('select[name="attestation_paiement[mode]"]')->count());
        self::assertSame(1, $crawler->filter('input[name="attestation_paiement[destinataireNom]"]')->count());
    }

    public function testLApercuRendUnPdfSansRienEnregistrer(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());
        $form = $crawler->filter('form[name="attestation_paiement"]')->form();
        $form['attestation_paiement[destinatairePrenom]'] = 'Ericka';
        $form['attestation_paiement[destinataireNom]'] = 'Marcoux';

        // Le bouton d'aperçu porte un formaction : la même saisie part sur l'autre route.
        $client->request(
            'POST',
            '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid() . '/apercu',
            $form->getPhpValues(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
        self::assertCount(0, $this->em()->getRepository(AttestationPaiement::class)->findAll());
    }

    public function testLEmissionEnregistreArchiveEtRetombeSurLaFiche(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());
        $form = $crawler->filter('form[name="attestation_paiement"]')->form();
        $form['attestation_paiement[destinatairePrenom]'] = 'Ericka';
        $form['attestation_paiement[destinataireNom]'] = 'Marcoux';
        $form['attestation_paiement[email]'] = '';

        $client->submit($form);

        self::assertResponseRedirects('/admin/effectif/joueurs/' . $licencie->getUuid());

        $attestations = $this->em()->getRepository(AttestationPaiement::class)->findAll();
        self::assertCount(1, $attestations);

        $attestation = $attestations[0];
        self::assertSame('120.00', $attestation->getMontant());
        self::assertSame('Marcoux', $attestation->getDestinataireNom());
        self::assertFalse($attestation->estEnvoyee(), 'Sans adresse saisie, aucun mail ne part.');
        self::assertNotNull($attestation->getDrivePath());
        self::assertFileExists((string) $attestation->getDrivePath());

        @unlink((string) $attestation->getDrivePath());
    }

    public function testLeTelechargementRegenereLePdfDepuisLesValeursFigees(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());
        $form = $crawler->filter('form[name="attestation_paiement"]')->form();
        $form['attestation_paiement[destinatairePrenom]'] = 'Ericka';
        $form['attestation_paiement[destinataireNom]'] = 'Marcoux';
        $client->submit($form);

        $attestation = $this->em()->getRepository(AttestationPaiement::class)->findAll()[0];
        $chemin = (string) $attestation->getDrivePath();

        // Simule l'archivage réussi : le fichier local disparaît, la colonne porte un ID Drive.
        @unlink($chemin);
        $attestation->setDrivePath('1AbCdEfGhIjKlMnOp');
        $this->em()->flush();

        $client->request('GET', '/admin/attestations-paiement/' . $attestation->getUuid() . '/telecharger');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testUneAdresseSaisieDeclencheLEnvoiAvecLePdfEnPieceJointe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $licencie = $this->licencieSolde();

        $crawler = $client->request('GET', '/admin/attestations-paiement/nouvelle/' . $licencie->getUuid());
        $form = $crawler->filter('form[name="attestation_paiement"]')->form();
        $form['attestation_paiement[destinatairePrenom]'] = 'Ericka';
        $form['attestation_paiement[destinataireNom]'] = 'Marcoux';
        // Le payeur peut avoir sa propre adresse : ce n'est pas celle du licencié.
        $form['attestation_paiement[email]'] = 'ericka@example.test';

        $client->submit($form);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('ericka@example.test', $mail->getTo()[0]->getAddress());
        self::assertCount(1, $mail->getAttachments(), 'L\'attestation part en pièce jointe.');

        $attestation = $this->em()->getRepository(AttestationPaiement::class)->findAll()[0];
        self::assertTrue($attestation->estEnvoyee());
        self::assertSame('ericka@example.test', $attestation->getEnvoyeeA());

        @unlink((string) $attestation->getDrivePath());
    }

    /* ── Outils ── */

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function licencieSolde(): Licencie
    {
        $licencie = $this->licencie();
        $this->paiement($licencie, '120.00');

        return $licencie;
    }

    private function licencie(): Licencie
    {
        $em = $this->em();

        $season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(120);
        $em->persist($season);

        $category = (new Category())->setCode('U13')->setLabel('U13')->setIsEcoleFoot(false);
        $em->persist($category);

        $licencie = (new Licencie())
            ->setNom('MARCOUX')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2013-04-02'))
            ->setCategory($category)
            ->setSeason($season);
        $em->persist($licencie);
        $em->flush();

        return $licencie;
    }

    private function paiement(Licencie $licencie, string $montant): void
    {
        $em = $this->em();

        $transaction = (new Transaction())
            ->setLicencie($licencie)
            ->setSeason($licencie->getSeason())
            ->setMontant($montant)
            ->setMode(PaymentMode::CB_ONLINE)
            ->setDatePaiement(new \DateTimeImmutable('2026-08-26'));

        $em->persist($transaction);
        $em->flush();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = $this->em();

        $club = self::getContainer()->get(ClubSettingsService::class)->get();
        $club
            ->setAssociationNom('Foyer de Soudron')
            ->setAssociationVille('Soudron')
            ->setSignataireCivilite(Civilite::MME)
            ->setSignataireNom('Claudine Moreaux')
            ->setSignataireQualite('trésorière');

        $user = (new User())->setEmail('admin-attestation@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

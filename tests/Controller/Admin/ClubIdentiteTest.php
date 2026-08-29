<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\Civilite;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Referentiel\SignatureCachetStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Identité de l'association et signataire de ses attestations.
 *
 * Le bloc signature a deux états, et ils ne se ressemblent pas : sans image, l'écran
 * propose un champ de fichier ; avec, il montre le paraphe en place et le champ ne
 * revient que sur décision de remplacer. Ces deux états sont ce que ce test verrouille.
 */
final class ClubIdentiteTest extends WebTestCase
{
    /** @var list<string> paraphes téléversés, effacés en fin de test */
    private array $signaturesDeposees = [];

    protected function tearDown(): void
    {
        // Téléverser écrit sur le disque : sans ce ménage, la suite accumule un
        // fichier par exécution dans var/test-signatures.
        foreach ($this->signaturesDeposees as $chemin) {
            @unlink($chemin);
        }

        $this->signaturesDeposees = [];

        parent::tearDown();
    }

    public function testLEcranProposeLIdentiteEtLeSignataire(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/identite');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="club_identite[associationNom]"]'));
        self::assertCount(1, $crawler->filter('input[name="club_identite[associationSiret]"]'));
        self::assertCount(1, $crawler->filter('select[name="club_identite[signataireCivilite]"]'));
        self::assertCount(1, $crawler->filter('input[name="club_identite[signataireNom]"]'));
        self::assertCount(1, $crawler->filter('input[name="club_identite[signataireQualite]"]'));
    }

    public function testLaSaisieEstEnregistreePourLeClub(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/identite');
        $form = $crawler->filter('form[name="club_identite"]')->form();
        $form['club_identite[associationNom]'] = 'Foyer de Soudron';
        $form['club_identite[associationVille]'] = 'Soudron';
        $form['club_identite[signataireCivilite]'] = Civilite::MME->value;
        $form['club_identite[signataireNom]'] = 'Claudine Moreaux';
        $form['club_identite[signataireQualite]'] = 'trésorière';

        $client->submit($form);

        $club = $this->rechargerReglages();
        self::assertSame('Foyer de Soudron', $club->getAssociationNom());
        self::assertSame(Civilite::MME, $club->getSignataireCivilite());
        self::assertTrue($club->peutAttesterUnPaiement());
    }

    /** Sans identité ni signataire, aucune attestation ne peut être émise. */
    public function testUnSignataireIncompletInterditDAttester(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/identite');
        $form = $crawler->filter('form[name="club_identite"]')->form();
        $form['club_identite[associationNom]'] = 'Foyer de Soudron';
        $form['club_identite[signataireNom]'] = 'Claudine Moreaux';
        // Qualité vidée : la migration l'avait pré-remplie, il faut l'effacer pour
        // reproduire une configuration incomplète.
        $form['club_identite[signataireQualite]'] = '';
        $client->submit($form);

        self::assertFalse($this->rechargerReglages()->peutAttesterUnPaiement());
    }

    /* ── Les deux états du bloc signature ── */

    public function testSansSignatureLeChampDeFichierEstDirectementOffert(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/club/identite');

        self::assertCount(1, $crawler->filter('input[type="file"][name="club_identite[signatureFichier]"]'));
        self::assertCount(0, $crawler->filter('.identite-signature-actuelle'));
        // Rien à supprimer : le formulaire caché n'a pas lieu d'être.
        self::assertCount(0, $crawler->filter('#signature-delete-form'));
    }

    public function testAvecSignatureLeParapheEstMontreEtLeChampMisEnRetrait(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->deposerUneSignature($client);

        $crawler = $client->request('GET', '/admin/club/identite');

        self::assertCount(1, $crawler->filter('.identite-signature-actuelle img'));
        // Le champ existe toujours, mais derrière la décision de remplacer.
        self::assertCount(1, $crawler->filter('.identite-signature-choix[x-show="remplacer"]'));
        self::assertCount(1, $crawler->filter('#signature-delete-form'));
    }

    public function testLeTeleversementRemplaceLaSignature(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->deposerUneSignature($client);

        $club = $this->rechargerReglages();
        self::assertNotNull($club->getSignatureCachetFichier());
        self::assertNotNull(
            self::getContainer()->get(SignatureCachetStorage::class)->dataUrl($club->getSignatureCachetFichier()),
            'Le fichier doit être lisible pour être embarqué dans le PDF.',
        );
    }

    public function testLaSuppressionEffaceLeFichierEtLaReference(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->deposerUneSignature($client);

        $nomFichier = (string) $this->rechargerReglages()->getSignatureCachetFichier();
        $storage = self::getContainer()->get(SignatureCachetStorage::class);
        $chemin = (string) $storage->chemin($nomFichier);

        $crawler = $client->request('GET', '/admin/club/identite');
        $client->submit($crawler->filter('#signature-delete-form')->form());

        self::assertResponseRedirects('/admin/club/identite');
        self::assertNull($this->rechargerReglages()->getSignatureCachetFichier());
        self::assertFileDoesNotExist($chemin, 'Un paraphe supprimé ne doit pas rester sur le disque.');
    }

    /* ── Outils ── */

    private function deposerUneSignature(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/admin/club/identite');
        $form = $crawler->filter('form[name="club_identite"]')->form();
        $champ = $form['club_identite[signatureFichier]'];
        self::assertInstanceOf(FileFormField::class, $champ);
        $champ->upload($this->fichierPng());

        $client->submit($form);

        $storage = self::getContainer()->get(SignatureCachetStorage::class);
        $chemin = $storage->chemin($this->rechargerReglages()->getSignatureCachetFichier());

        if ($chemin !== null) {
            $this->signaturesDeposees[] = $chemin;
        }
    }

    /** Un PNG 1×1 valide : le contrôle de type MIME est réel, un fichier bidon serait refusé. */
    private function fichierPng(): string
    {
        $chemin = sys_get_temp_dir() . '/signature_test_' . uniqid() . '.png';
        file_put_contents($chemin, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        return $chemin;
    }

    private function rechargerReglages(): \App\Entity\ClubSettings
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return self::getContainer()->get(ClubSettingsService::class)->get();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setSuperAdmin(true)->setEmail('admin-identite@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}

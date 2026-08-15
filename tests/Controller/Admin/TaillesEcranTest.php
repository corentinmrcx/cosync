<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\Taille;
use App\Entity\User;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le référentiel des tailles, réglé par le club.
 *
 * Le libellé d'une taille est recopié tel quel dans les dossiers et les mouvements : c'est
 * ce qui rend son renommage et sa suppression irréversibles, et donc gardés.
 */
final class TaillesEcranTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testLeReferentielLivreParLaMigrationSepareLesDeuxPublics(): void
    {
        $client = $this->loginAdmin();

        $html = $client->request('GET', '/admin/club/tailles')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('128', $html);
        self::assertStringContainsString('Stock uniquement', $html, 'Les étiquetages fournisseur sont marqués.');
        self::assertTrue($this->taille('128')->isProposeeAuxLicencies() === false);
        self::assertTrue($this->taille('12 ans')->isProposeeAuxLicencies());
    }

    public function testUneNouvelleTailleSAjouteEtSePlaceEnFinDeListe(): void
    {
        $client = $this->loginAdmin();
        $avant = count($this->repository()->findAllOrdered());

        $client->request('POST', '/admin/club/tailles/nouvelle', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/nouvelle"]'),
            'libelle' => '5XL',
            'type' => 'vetement',
            'groupe' => 'Tailles adultes',
            'proposee' => '1',
        ]);

        self::assertResponseRedirects('/admin/club/tailles');
        $tailles = $this->repository()->findAllOrdered();
        self::assertCount($avant + 1, $tailles);
        self::assertSame('5XL', end($tailles)->getLibelle());
    }

    public function testUneTailleEnDoubleEstRefusee(): void
    {
        $client = $this->loginAdmin();

        $client->request('POST', '/admin/club/tailles/nouvelle', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/nouvelle"]'),
            'libelle' => 'XL',
            'type' => 'vetement',
        ]);

        self::assertStringContainsString('existe déjà', $client->followRedirect()->html());
    }

    /**
     * Retirer une taille des formulaires n'efface rien : elle reste rangeable en stock, et
     * les dossiers qui la portent gardent un sens.
     */
    public function testUneTailleSeRetireDesFormulairesSansDisparaitreDuStock(): void
    {
        $client = $this->loginAdmin();
        $taille = $this->taille('4XL');

        $client->request('POST', '/admin/club/tailles/' . $taille->getId() . '/modifier', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/' . $taille->getId() . '/modifier"]'),
            'libelle' => '4XL',
            'groupe' => 'Tailles adultes',
        ]);

        self::assertResponseRedirects('/admin/club/tailles');
        $this->em->clear();
        self::assertFalse($this->taille('4XL')->isProposeeAuxLicencies());
    }

    public function testUneTailleEmployeeNeSeRenommePasEtNeSeSupprimePas(): void
    {
        $client = $this->loginAdmin();
        $taille = $this->taille('M');
        $this->mouvementEnTaille('M');

        $client->request('POST', '/admin/club/tailles/' . $taille->getId() . '/modifier', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/' . $taille->getId() . '/modifier"]'),
            'libelle' => 'Medium',
            'proposee' => '1',
        ]);
        self::assertStringContainsString('Impossible de renommer', $client->followRedirect()->html());

        // L'écran ne propose même plus la suppression : le bouton reste, désactivé, pour
        // dire pourquoi. Le refus côté service est couvert par TailleServiceTest.
        $crawler = $client->request('GET', '/admin/club/tailles');
        self::assertCount(0, $crawler->filter('form[action="/admin/club/tailles/' . $taille->getId() . '/supprimer"]'));
        self::assertGreaterThan(0, $crawler->filter('.taille-btn-disabled')->count());

        $this->em->clear();
        self::assertSame('M', $this->taille('M')->getLibelle());
    }

    public function testUneTailleInutiliseeSeSupprime(): void
    {
        $client = $this->loginAdmin();
        $taille = $this->taille('176');

        $client->request('POST', '/admin/club/tailles/' . $taille->getId() . '/supprimer', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/' . $taille->getId() . '/supprimer"]'),
        ]);

        self::assertResponseRedirects('/admin/club/tailles');
        $this->em->clear();
        self::assertNull($this->repository()->findOneByLibelle(TailleType::VETEMENT, '176'));
    }

    /** L'ordre du référentiel est celui de tous les sélecteurs, public compris. */
    public function testLOrdreSeRegleAuGlisserDeposerEtSuitJusquAuFormulairePublic(): void
    {
        $client = $this->loginAdmin();
        $ids = array_map(
            static fn (Taille $t): int => $t->getId(),
            array_filter(
                $this->repository()->findAllOrdered(),
                static fn (Taille $t): bool => $t->getType() === TailleType::VETEMENT,
            ),
        );

        // La taille « 12 ans » passe en tête.
        $douzeAns = $this->taille('12 ans')->getId();
        $nouvelOrdre = array_merge([$douzeAns], array_values(array_diff($ids, [$douzeAns])));

        $client->request('POST', '/admin/club/tailles/reordonner', [
            '_token' => $this->jeton($client, 'form[action="/admin/club/tailles/reordonner"]'),
            'ordre' => $nouvelOrdre,
        ]);

        self::assertResponseRedirects('/admin/club/tailles');
        $this->em->clear();
        self::assertSame('12 ans', $this->repository()->findAllOrdered()[0]->getLibelle());
    }

    private function repository(): TailleRepository
    {
        return self::getContainer()->get(TailleRepository::class);
    }

    private function taille(string $libelle): Taille
    {
        $taille = $this->repository()->findOneBy(['libelle' => $libelle]);
        self::assertNotNull($taille, sprintf('Taille "%s" absente du référentiel livré par la migration.', $libelle));

        return $taille;
    }

    private function mouvementEnTaille(string $libelle): void
    {
        $item = (new StockItem())->setNom('Maillot');
        $this->em->persist($item);

        $this->em->persist((new StockMovement())
            ->setItem($item)
            ->setQuantite(1)
            ->setType(StockMovementType::ENTREE)
            ->setSource(StockMovementSource::MANUEL)
            ->setTaille($libelle));

        $this->em->flush();
    }

    /** Jeton repris du formulaire réellement rendu : le gestionnaire CSRF le stocke en session. */
    private function jeton(KernelBrowser $client, string $selecteur): string
    {
        $crawler = $client->request('GET', '/admin/club/tailles');
        $champ = $crawler->filter($selecteur . ' input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), 'Formulaire introuvable : ' . $selecteur);

        return (string) $champ->first()->attr('value');
    }

    private function loginAdmin(): KernelBrowser
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-tailles@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($season);

        $this->em->persist($season);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}

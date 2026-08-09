<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\StockCategory;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les écrans de stock rendus avec des données réelles.
 *
 * AccesAdminTest les visite déjà, mais sur une base vide : les boucles n'y sont jamais
 * parcourues, donc rien de ce qui est affiché article par article n'est vérifié.
 */
final class StockEcransTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testLeTableauDeGestionAfficheChaqueArticleAvecSaVentilationParTaille(): void
    {
        $client = $this->loginAdmin();

        $veste = $this->makeItem('Veste de survêtement', seuil: 5);
        $this->entree($veste, 3, 'L');
        $this->entree($veste, 2, 'M');

        $html = $client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Veste de survêtement', $html);
        self::assertStringContainsString('Stock bas', $html, '5 en stock pour un seuil de 5.');
    }

    public function testUnArticleSansMouvementEstSignaleEnRupture(): void
    {
        $client = $this->loginAdmin();
        $this->makeItem('Chasuble', seuil: 2);

        $html = $client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Rupture', $html);
    }

    public function testLeTableauDeBordCompteSeparementRupturesEtStockBas(): void
    {
        $client = $this->loginAdmin();

        $enRupture = $this->makeItem('Chasuble', seuil: 2);
        $stockBas = $this->makeItem('Ballon', seuil: 5);
        $this->entree($stockBas, 4, null);
        $suffisant = $this->makeItem('Plot', seuil: 1);
        $this->entree($suffisant, 50, null);

        $html = $client->request('GET', '/admin/stock')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Chasuble', $html);
        self::assertStringContainsString('Ballon', $html);
        self::assertStringNotContainsString('Plot', $html, 'Un article au-dessus de son seuil ne remonte pas en alerte.');
        self::assertSame($enRupture->getAlertSeuil(), 2);
    }

    public function testLaFeuilleDInventaireEstUnPdf(): void
    {
        $client = $this->loginAdmin();

        $veste = $this->makeItem('Veste de survêtement', seuil: 5);
        $this->entree($veste, 3, 'L');
        $this->makeItem('Article sans taille ni mouvement');

        $client->request('GET', '/admin/stock/inventaire.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    private function makeItem(string $nom, ?int $seuil = null): StockItem
    {
        $category = (new StockCategory())->setName('Équipement')->setPosition(1);
        $this->em->persist($category);

        $item = (new StockItem())->setNom($nom)->setCategory($category)->setAlertSeuil($seuil);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function entree(StockItem $item, int $quantite, ?string $taille): void
    {
        $mouvement = (new StockMovement())
            ->setItem($item)
            ->setQuantite($quantite)
            ->setType(StockMovementType::ENTREE)
            ->setSource(StockMovementSource::MANUEL)
            ->setTaille($taille);

        $this->em->persist($mouvement);
        $this->em->flush();
    }

    private function loginAdmin(): KernelBrowser
    {
        $user = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}

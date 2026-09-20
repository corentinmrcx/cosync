<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DotationBesoin;
use App\Entity\Fournisseur;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La forme de l'écran « à commander » : un article, ses tailles dessous.
 *
 * À plat, la même veste revenait quatre fois dans la page, dans l'ordre où les licenciés
 * s'étaient inscrits — impossible à relire en face d'un devis.
 */
final class CommandeListeGroupeeTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    public function testUnArticleSesTaillesDessousEtLesFournisseursTries(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $zebra = $this->makeFournisseur('Zebra');
        $alpha = $this->makeFournisseur('Alpha');

        $veste = $this->makeItem('Veste', $zebra);
        $this->makeBesoin($veste, 'XL');
        $this->makeBesoin($veste, 'S');
        $this->makeBesoin($veste, 'M');

        $sac = $this->makeItem('Sac', $alpha);
        $this->makeBesoin($sac, 'L');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Alpha', 'Zebra'],
            $crawler->filter('.cmd-fourn-title')->each(static fn ($n): string => trim($n->text())),
        );

        // Une ligne par article, et les tailles de la veste rangées dessous.
        self::assertSame(
            ['Sac', 'Veste'],
            $crawler->filter('.cmd-article-nom')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame(
            ['S', 'M', 'XL'],
            $crawler->filter('.cmd-detail .dot-item-taille')->each(static fn ($n): string => trim($n->text())),
        );

        // Le sac n'a qu'une déclinaison : sa taille se lit sur la ligne de l'article, sans
        // ligne de détail qui répéterait les mêmes nombres.
        self::assertSame(
            'L',
            trim($crawler->filter('.cmd-article-ligne .dot-item-taille')->text()),
        );
    }

    private function makeFournisseur(string $nom): Fournisseur
    {
        $fournisseur = (new Fournisseur())->setNom($nom);
        $this->em->persist($fournisseur);

        return $fournisseur;
    }

    private function makeItem(string $nom, Fournisseur $fournisseur): StockItem
    {
        $item = (new StockItem())
            ->setNom($nom)->setFournisseur($fournisseur)
            ->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->persist($item);

        return $item;
    }

    private function makeBesoin(StockItem $item, string $taille): void
    {
        $this->em->persist(
            (new DotationBesoin())->setSeason($this->season)->setStockItem($item)->setTaille($taille)->setQuantite(1),
        );
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-commandes-groupees@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $user->setSelectedSeason($this->season);
        $this->em->flush();

        $client->loginUser($user);
    }
}

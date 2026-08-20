<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\DotationBesoin;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce qu'on lit au moment de commander.
 *
 * Le club crée un article par déclinaison : six s'appellent « Chaussettes » et ne se
 * distinguent que par leur marque et leur couleur. Un bon de commande qui n'affiche que le
 * nom ne dit pas quoi acheter (§7.6 ter) — et la référence catalogue épargne la recherche
 * chez le fournisseur.
 */
final class CommandeArticleDesignationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    public function testLaListeACommanderDesigneLArticleEtDonneSaReference(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $jaune = $this->makeItem('T-shirt', 'Erima', 'Jaune');
        $jaune->setRefCatalogue('1082334')->setLienAchat('https://www.erima-online.com/1082334');
        $this->makeBesoin($jaune, 'M');

        // Même nom, autre déclinaison : c'est le cas que le nom seul ne sait pas distinguer.
        $noir = $this->makeItem('T-shirt', 'Erima', 'Noir');
        $this->makeBesoin($noir, 'M');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['T-shirt · Erima · Jaune', 'T-shirt · Erima · Noir'],
            $crawler->filter('.cmd-article-nom')->each(static fn ($n): string => trim($n->text())),
        );

        $meta = $crawler->filter('.cmd-article-meta');
        self::assertCount(1, $meta, 'L\'article sans référence ni lien n\'affiche pas de seconde ligne vide.');
        self::assertStringContainsString('réf. 1082334', $meta->text());
        self::assertSame(
            'https://www.erima-online.com/1082334',
            $crawler->filter('.cmd-article-lien')->attr('href'),
        );
    }

    public function testLeBonDeCommandeReprendLaMemeDesignation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $item = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $item->setRefCatalogue('2181901');

        $commande = (new Commande())->setSeason($this->season)->setStatut(CommandeStatut::BROUILLON);
        $ligne = (new CommandeLigne())->setStockItem($item)->setTaille('44')->setQuantite(3);
        $commande->addLigne($ligne);
        $this->em->persist($commande);
        $this->em->persist($ligne);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes/' . $commande->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('Chaussettes · Erima · Noir', trim($crawler->filter('.cmd-article-nom')->text()));
        self::assertStringContainsString('réf. 2181901', $crawler->filter('.cmd-article-meta')->text());

        // Le PDF est le document qu'on a sous les yeux chez le fournisseur : il doit porter
        // la même désignation, et la référence dans sa propre colonne.
        $client->request('GET', '/admin/commandes/' . $commande->getId() . '/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
    }

    private function makeItem(string $nom, string $marque, string $couleur): StockItem
    {
        $item = (new StockItem())
            ->setNom($nom)->setMarque($marque)->setCouleur($couleur)
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
        $user = (new User())->setEmail('admin-commandes-designation@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $user->setSelectedSeason($this->season);
        $this->em->flush();

        $client->loginUser($user);
    }
}

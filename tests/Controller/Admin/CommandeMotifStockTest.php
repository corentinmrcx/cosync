<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DotationBesoin;
use App\Entity\GrilleTaille;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pourquoi le stock d'une ligne à commander est introuvable.
 *
 * L'écran affichait « 46 · stock 0 · à commander 3 » pendant que neuf paires dormaient au
 * local sous le « 44 » d'Erima — l'étiquette du 44-46. Sur une saison, le club a commandé
 * 80 articles au lieu de 55. La ligne dit désormais son motif plutôt que de laisser conclure
 * qu'il n'y a rien (CLAUDE.md §5 : une action injouable affiche son motif).
 */
final class CommandeMotifStockTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    public function testUneLigneSansStockDitSousQuelsLibellesLArticleEstRange(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $chaussettes = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $this->makeStock($chaussettes, '44', 9);
        $this->makeStock($chaussettes, '41', 4);
        $this->makeBesoin($chaussettes, '46');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        $motif = $crawler->filter('.cmd-motif');
        self::assertCount(1, $motif);
        self::assertStringContainsString('Aucun stock en « 46 »', $motif->text());
        self::assertStringContainsString(
            'rangé en 41, 44',
            $motif->text(),
            'Les libellés se lisent dans l\'ordre du référentiel, comme partout ailleurs.',
        );
        self::assertStringContainsString(
            'Aucune grille de tailles',
            $motif->text(),
            'Cause n°1 : sans grille rattachée, le déclaré n\'est jamais traduit en étiquette fournisseur.',
        );
        self::assertCount(1, $crawler->filter('.cmd-motif a[href="/admin/club/grilles-tailles"]'));
    }

    public function testUnArticleAvecGrilleGardeLeMotifMaisPasLaCause(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $grille = (new GrilleTaille())->setNom('Chaussettes Erima')->setType(TailleType::POINTURE);
        $this->em->persist($grille);

        $chaussettes = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $chaussettes->setGrilleTaille($grille);
        $this->makeStock($chaussettes, '44', 9);
        // Une taille que la grille ne mentionne pas passe telle quelle : le stock reste
        // introuvable, mais le rattachement n'est plus ce qu'il faut corriger.
        $this->makeBesoin($chaussettes, '46');
        $this->em->flush();

        $motif = $client->request('GET', '/admin/commandes')->filter('.cmd-motif');

        self::assertCount(1, $motif);
        self::assertStringContainsString('Aucun stock en « 46 »', $motif->text());
        self::assertStringNotContainsString('Aucune grille de tailles', $motif->text());
    }

    public function testUnArticleDontLeClubNAVraimentRienNeDitRien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeBesoin($this->makeItem('Veste', 'Erima', 'Rouge'), 'L');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('.cmd-motif'),
            'Commander ce qu\'on n\'a pas est le cas ordinaire : l\'expliquer noierait les lignes qui ont quelque chose à dire.',
        );
    }

    private function makeItem(string $nom, string $marque, string $couleur): StockItem
    {
        $item = (new StockItem())
            ->setNom($nom)->setMarque($marque)->setCouleur($couleur)
            ->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->persist($item);

        return $item;
    }

    private function makeStock(StockItem $item, string $taille, int $quantite): void
    {
        $this->em->persist(
            (new StockMovement())
                ->setItem($item)->setTaille($taille)
                ->setQuantite($quantite)->setType(StockMovementType::ENTREE),
        );
    }

    private function makeBesoin(StockItem $item, string $taille): void
    {
        $this->em->persist(
            (new DotationBesoin())->setSeason($this->season)->setStockItem($item)->setTaille($taille)->setQuantite(3),
        );
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-commandes-motif@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $user->setSelectedSeason($this->season);
        $this->em->flush();

        $client->loginUser($user);
    }
}

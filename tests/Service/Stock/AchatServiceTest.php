<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Enum\DotationBesoinStatut;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Service\Stock\AchatService;

final class AchatServiceTest extends StockIntegrationTestCase
{
    private function achat(): AchatService
    {
        return $this->service(AchatService::class);
    }

    /**
     * @param array<int|string, array{lignes: array<int, array<string, mixed>>}> $groupes
     *
     * @return array<string, mixed>|null la première ligne trouvée pour (nom, taille)
     */
    private function findLigne(array $groupes, string $nom, ?string $taille): ?array
    {
        foreach ($groupes as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                if ($ligne['stockItem']->getNom() === $nom && $ligne['taille'] === $taille) {
                    return $ligne;
                }
            }
        }

        return null;
    }

    public function testEquationBesoinsMoinsStockMoinsEnAttente(): void
    {
        $season = $this->makeSeason();
        $f = $this->makeFournisseur('Sport2000');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $f);

        $this->makeBesoin($season, $veste, 'L', 10);                 // besoin 10
        $this->makeMovement($veste, 3, StockMovementType::ENTREE, 'L'); // stock 3
        $this->makeCommandeEnAttente($season, $veste, 'L', 2, $f);   // en attente 2
        $this->em->flush();

        $ligne = $this->findLigne($this->achat()->computeACommander($season), 'Veste', 'L');

        self::assertNotNull($ligne);
        self::assertSame(10, $ligne['besoin']);
        self::assertSame(3, $ligne['stock']);
        self::assertSame(2, $ligne['enAttente']);
        self::assertSame(5, $ligne['aCommander'], '10 − 3 − 2 = 5.');
    }

    public function testStockSepareParTaille(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 5);
        $this->makeBesoin($season, $veste, 'M', 3);
        $this->makeMovement($veste, 5, StockMovementType::ENTREE, 'L'); // couvre les L
        $this->em->flush();

        $groupes = $this->achat()->computeACommander($season);

        self::assertNull($this->findLigne($groupes, 'Veste', 'L'), 'Les L sont couverts.');
        $ligneM = $this->findLigne($groupes, 'Veste', 'M');
        self::assertNotNull($ligneM);
        self::assertSame(3, $ligneM['aCommander']);
    }

    public function testRegroupementParFournisseur(): void
    {
        $season = $this->makeSeason();
        $fa = $this->makeFournisseur('Fournisseur A');
        $fb = $this->makeFournisseur('Fournisseur B');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $fa);
        $short = $this->makeItem('Short', StockItemVetementType::BAS, $fb);

        $this->makeBesoin($season, $veste, 'L', 4);
        $this->makeBesoin($season, $short, 'M', 6);
        $this->em->flush();

        $groupes = $this->achat()->computeACommander($season);
        $noms = array_map(static fn (array $g): string => $g['fournisseurNom'], $groupes);

        self::assertCount(2, $groupes);
        self::assertContains('Fournisseur A', $noms);
        self::assertContains('Fournisseur B', $noms);
    }

    public function testBesoinsDonnesEtCouvertsIgnores(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $this->makeBesoin($season, $veste, 'L', 2, DotationBesoinStatut::DONNE); // déjà donné
        $this->makeBesoin($season, $veste, 'M', 2);                              // mais couvert par le stock
        $this->makeMovement($veste, 2, StockMovementType::ENTREE, 'M');
        $this->em->flush();

        self::assertSame([], $this->achat()->computeACommander($season), 'Rien à commander.');
    }
}

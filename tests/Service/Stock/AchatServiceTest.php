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

    /**
     * L'ordre est celui de la relecture, pas celui des besoins rencontrés : le bon de
     * commande recopie ces lignes telles quelles.
     */
    public function testOrdreDesFournisseursDesArticlesEtDesTailles(): void
    {
        $season = $this->makeSeason();
        $alpha = $this->makeFournisseur('Alpha');
        $zebra = $this->makeFournisseur('Zebra');

        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $zebra);
        $short = $this->makeItem('Short', StockItemVetementType::BAS, $zebra);
        $sac = $this->makeItem('Sac', null, $alpha);
        $gants = $this->makeItem('Gants', null, null);

        // Saisis dans le désordre, comme les licenciés arrivent.
        $this->makeBesoin($season, $veste, 'XL', 1);
        $this->makeBesoin($season, $veste, 'S', 2);
        $this->makeBesoin($season, $veste, 'M', 3);
        $this->makeBesoin($season, $short, 'L', 1);
        $this->makeBesoin($season, $sac, null, 4);
        $this->makeBesoin($season, $gants, null, 1);
        $this->em->flush();

        $groupes = $this->achat()->computeACommander($season);

        self::assertSame(
            ['Alpha', 'Zebra', 'Sans fournisseur'],
            array_column($groupes, 'fournisseurNom'),
            'Fournisseurs alphabétiques, le fourre-tout en fin de liste.',
        );

        $lignes = array_map(
            static fn (array $ligne): string => $ligne['stockItem']->getNom() . ' ' . ($ligne['taille'] ?? '—'),
            $groupes[1]['lignes'],
        );

        self::assertSame(
            ['Short L', 'Veste S', 'Veste M', 'Veste XL'],
            $lignes,
            'Articles par désignation, tailles dans l\'ordre du référentiel.',
        );
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

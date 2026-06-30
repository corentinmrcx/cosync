<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\StockCategory;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Service\PurgeService;

final class PurgeServiceTest extends StockIntegrationTestCase
{
    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    public function testPurgeVideToutesLesDonneesMaisGardeLesReferentiels(): void
    {
        // — Sème la chaîne complète, y compris dotations + commandes (ce qui faisait échouer l'ancienne purge) —
        $season = $this->makeSeason();
        $cat    = $this->makeCategory();
        $team   = $this->makeTeam($season);
        $four   = $this->makeFournisseur();

        $sc = (new StockCategory())->setName('Maillots');
        $this->em->persist($sc);

        $item = $this->makeItem('Veste', StockItemVetementType::HAUT, $four);
        $item->setCategory($sc);

        $licencie = $this->makeLicencie($season, $cat, $team);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $this->makeBesoin($season, $item, 'L', 1);

        $this->makeMovement($item, 5, StockMovementType::ENTREE, 'L');
        $this->makeCommandeEnAttente($season, $item, 'L', 3, $four);

        $this->em->flush();
        $this->em->clear();

        // Référentiels présents avant purge (conservés ensuite).
        $categoriesAvant = $this->rowCount('category');
        $rolesAvant      = $this->rowCount('dirigeant_role');
        $usersAvant      = $this->rowCount('"user"');

        self::assertGreaterThan(0, $this->rowCount('dotation_besoin'), 'Le besoin doit exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('commande_ligne'), 'La ligne de commande doit exister avant purge.');

        // — Purge —
        $this->service(PurgeService::class)->purgeAll();

        // Toutes les tables de données sont vides.
        $videes = [
            'transaction', 'commande_ligne', 'dotation_modele_ligne', 'dotation_affectation',
            'dotation_besoin', 'stock_movement', 'commande', 'dotation_modele', 'dossier_club',
            'licencie', 'dirigeant', 'stock_item', 'fournisseur', 'stock_category', 'team', 'season',
        ];
        foreach ($videes as $table) {
            self::assertSame(0, $this->rowCount($table), sprintf('La table "%s" doit être vide après purge.', $table));
        }

        // Référentiels et comptes conservés intacts.
        self::assertSame($categoriesAvant, $this->rowCount('category'), 'Les catégories FFF sont conservées.');
        self::assertSame($rolesAvant, $this->rowCount('dirigeant_role'), 'Les rôles dirigeants sont conservés.');
        self::assertSame($usersAvant, $this->rowCount('"user"'), 'Les comptes admin sont conservés.');
    }
}

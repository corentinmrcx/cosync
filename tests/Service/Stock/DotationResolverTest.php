<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Licencie;
use App\Enum\StockItemVetementType;
use App\Service\Dotation\DotationResolver;

final class DotationResolverTest extends StockIntegrationTestCase
{
    private function resolver(): DotationResolver
    {
        return $this->service(DotationResolver::class);
    }

    public function testResolutionParCategorieEtTailleDepuisLeDossier(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $modele = $this->makeModele($season, 'Sénior');
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $licencie = $this->makeLicencie($season, $cat, null, 'L');

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $lignes = $this->resolver()->resolveDotation($licencie);

        self::assertCount(1, $lignes);
        self::assertSame('Veste', $lignes[0]['stockItem']->getNom());
        self::assertSame('L', $lignes[0]['taille'], 'La taille doit être déduite du dossier (tailleHaut).');
    }

    public function testAffectationIndividuelleEcraseLaCategorie(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $itemCat = $this->makeItem('Maillot', StockItemVetementType::HAUT);
        $modeleCat = $this->makeModele($season, 'Standard');
        $this->addLigne($modeleCat, $itemCat, 1);
        $this->affecterCategorie($season, $modeleCat, $cat);

        $itemIndiv = $this->makeItem('Veste capitaine', StockItemVetementType::HAUT);
        $modeleIndiv = $this->makeModele($season, 'Capitaine');
        $this->addLigne($modeleIndiv, $itemIndiv, 1);

        $licencie = $this->makeLicencie($season, $cat, null, 'M');
        $this->affecterLicencie($season, $modeleIndiv, $licencie);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $modele = $this->resolver()->resolveModele($licencie);
        self::assertNotNull($modele);
        self::assertSame('Capitaine', $modele->getNom(), 'L\'affectation individuelle est prioritaire.');
    }

    public function testGroupeDeChoixSansChoixPrendLaPremiereOption(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $sweat = $this->makeItem('Sweat', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Au choix');
        $this->addLigne($modele, $veste, 1, 'haut-au-choix');
        $this->addLigne($modele, $sweat, 1, 'haut-au-choix');
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $lignes = $this->resolver()->resolveDotation($licencie);

        self::assertCount(1, $lignes, 'Un groupe de choix ne produit qu\'une ligne.');
        self::assertSame('Veste', $lignes[0]['stockItem']->getNom(), 'Sans choix stocké → première option.');
    }

    public function testModeleInactifNeDotePersonne(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Veste', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Kit en préparation');
        $modele->setActif(false);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertNull($this->resolver()->resolveModele($licencie), 'Un kit désactivé ne s\'applique pas.');
        self::assertSame([], $this->resolver()->resolveDotation($licencie));
    }

    public function testAPrioriteEgaleLaDerniereAffectationGagne(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $premier = $this->makeModele($season, 'Premier');
        $this->addLigne($premier, $this->makeItem('Veste', StockItemVetementType::HAUT), 1);
        $this->affecterCategorie($season, $premier, $cat);

        $second = $this->makeModele($season, 'Second');
        $this->addLigne($second, $this->makeItem('Sweat', StockItemVetementType::HAUT), 1);
        $this->affecterCategorie($season, $second, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertSame(
            'Second',
            $this->resolver()->resolveModele($licencie)?->getNom(),
            'Deux affectations de même priorité : la plus récente gagne, de façon reproductible.',
        );
    }

    public function testSansAffectationAucuneDotation(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        self::assertNull($this->resolver()->resolveModele($licencie));
        self::assertSame([], $this->resolver()->resolveDotation($licencie));
    }
}

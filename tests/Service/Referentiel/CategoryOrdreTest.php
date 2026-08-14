<?php declare(strict_types=1);

namespace App\Tests\Service\Referentiel;

use App\Entity\Category;
use App\Service\Referentiel\CategoryOrdre;
use PHPUnit\Framework\TestCase;

final class CategoryOrdreTest extends TestCase
{
    public function testLesCategoriesSontTrieesParAgeEtNonParCode(): void
    {
        // Un tri alphabétique placerait U10 avant U6, un tri par id suivrait l'ordre de création.
        $codes = $this->trier(['SENIOR', 'U13', 'U6', 'U10F', 'U6F', 'U10', 'FOOTLOISIR', 'VETERAN']);

        self::assertSame(
            ['U6', 'U6F', 'U10', 'U10F', 'U13', 'SENIOR', 'VETERAN', 'FOOTLOISIR'],
            $codes,
        );
    }

    public function testUneCategorieFemininSuitSaCategorieMasculine(): void
    {
        self::assertSame(['U7', 'U7F', 'U8'], $this->trier(['U8', 'U7F', 'U7']));
        self::assertSame(['SENIOR', 'SENIORF'], $this->trier(['SENIORF', 'SENIOR']));
    }

    public function testUneCategorieInconnuePasseEnFinDeListe(): void
    {
        self::assertSame(['U19', 'SENIOR', 'ARBITRE'], $this->trier(['ARBITRE', 'SENIOR', 'U19']));
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function trier(array $codes): array
    {
        $categories = array_map(
            static fn (string $code): Category => (new Category())->setCode($code)->setLabel($code)->setIsEcoleFoot(false),
            $codes,
        );

        return array_map(
            static fn (Category $c): string => $c->getCode(),
            CategoryOrdre::trier($categories),
        );
    }
}

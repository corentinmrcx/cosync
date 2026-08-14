<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\Category;

/**
 * Référentiel de l'ordre d'affichage des catégories FFF.
 *
 * L'ordre naturel est celui des âges : U6, U6F, U7, U7F… puis les catégories adultes.
 * Ni l'id (qui suit l'ordre de création, donc renvoie les ajouts manuels en bas de liste)
 * ni le code (qui trierait U10 avant U6) ne le donnent — d'où ce rang calculé.
 */
final class CategoryOrdre
{
    /** Rang des catégories sans tranche d'âge, dans l'ordre où le club les lit. */
    private const RANGS_ADULTES = [
        'SENIOR' => 1000,
        'VETERAN' => 1100,
        'FOOTLOISIR' => 1200,
    ];

    /** Une catégorie inconnue du référentiel passe en fin de liste, jamais au milieu. */
    private const RANG_INCONNU = 9000;

    /**
     * @param list<Category> $categories
     *
     * @return list<Category>
     */
    public static function trier(array $categories): array
    {
        usort(
            $categories,
            static fn (Category $a, Category $b): int => [self::rang($a->getCode()), $a->getCode()]
                <=> [self::rang($b->getCode()), $b->getCode()],
        );

        return $categories;
    }

    /** Le suffixe F place la catégorie féminine juste après sa catégorie masculine. */
    public static function rang(string $code): int
    {
        $code = strtoupper(trim($code));
        $feminin = str_ends_with($code, 'F') ? 1 : 0;
        $base = $feminin === 1 ? substr($code, 0, -1) : $code;

        if (preg_match('/^U(\d{1,2})$/', $base, $m) === 1) {
            return ((int) $m[1]) * 10 + $feminin;
        }

        return (self::RANGS_ADULTES[$base] ?? self::RANG_INCONNU) + $feminin;
    }
}

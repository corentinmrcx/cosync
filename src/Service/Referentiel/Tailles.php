<?php declare(strict_types=1);

namespace App\Service\Referentiel;

/**
 * Référentiel unique des tailles d'équipement et des pointures.
 *
 * Les licenciés et dirigeants ne déclarent que des tailles adulte ; les tailles enfant
 * n'existent que côté stock, où le club range des articles achetés pour l'école de foot.
 */
final class Tailles
{
    /** @var list<string> */
    public const ADULTE = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL'];

    /** @var list<string> */
    public const ENFANT = ['6 ans', '8 ans', '10 ans', '12 ans', '14 ans', '16 ans'];

    private const POINTURE_MIN = 24;
    private const POINTURE_MAX = 50;

    /**
     * Toutes les tailles qu'un article de stock peut porter, dans l'ordre d'affichage.
     *
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [...self::ADULTE, ...self::ENFANT];
    }

    /** @return list<string> */
    public static function pointures(): array
    {
        return array_map('strval', range(self::POINTURE_MIN, self::POINTURE_MAX));
    }

    /**
     * Choix groupés Adulte / Enfant pour un ChoiceType.
     *
     * @return array<string, array<string, string>>
     */
    public static function choixGroupes(): array
    {
        return [
            'Adulte' => array_combine(self::ADULTE, self::ADULTE),
            'Enfant' => array_combine(self::ENFANT, self::ENFANT),
        ];
    }

    /** @return array<string, string> */
    public static function choixPointures(): array
    {
        $pointures = self::pointures();

        return array_combine($pointures, $pointures);
    }

    /** Rang d'affichage d'une taille ; les valeurs inconnues sont rejetées en fin de liste. */
    public static function rang(string $taille): int
    {
        $index = array_search($taille, self::toutes(), true);

        return $index === false ? \PHP_INT_MAX : $index;
    }
}

<?php declare(strict_types=1);

namespace App\Service\Import\Layout;

use App\DTO\ImportRowData;

/**
 * Un layout mappe un format d'export FootClubs (jeu de colonnes) vers la ligne normalisée
 * commune `ImportRowData`, et déclare son comportement d'envoi de mail. Toute la logique
 * d'upsert reste dans `ImportService` : le layout ne fait que du mapping de colonnes.
 *
 * @phpstan-type ColumnMap array<string, int>
 */
interface ImportLayoutInterface
{
    /**
     * Ce layout reconnaît-il ce fichier d'après ses en-têtes (normalisés en minuscules) ?
     *
     * @param array<string, int> $columns  en-tête normalisé => index de colonne
     */
    public function supports(array $columns): bool;

    /**
     * Normalise une ligne brute. Retourne une ligne de type SKIP si elle doit être ignorée.
     *
     * @param array<int, mixed>  $row      cellules de la ligne (indexées par position)
     * @param array<string, int> $columns  en-tête normalisé => index de colonne
     */
    public function map(array $row, array $columns): ImportRowData;

    /** Libellé lisible du format, affiché dans le rapport d'import. */
    public function label(): string;
}

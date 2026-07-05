<?php declare(strict_types=1);

namespace App\Service\Import\Layout;

/**
 * Lecture de cellule par nom de colonne normalisé, partagée par les layouts.
 */
trait ReadsColumnsTrait
{
    /**
     * @param array<int, mixed>  $row
     * @param array<string, int> $columns
     */
    protected function value(array $row, array $columns, string $name): ?string
    {
        if (!isset($columns[$name])) {
            return null;
        }
        $raw = $row[$columns[$name]] ?? null;

        return $raw !== null ? (string) $raw : null;
    }
}

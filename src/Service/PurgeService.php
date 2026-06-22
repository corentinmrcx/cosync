<?php declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class PurgeService
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Supprime toutes les données métier dans l'ordre des FK.
     * Conserve : User, Season, Category, Team, DirigeantRole.
     *
     * @return array<string, int> nombre de lignes supprimées par table
     */
    public function purgeAll(): array
    {
        $tables = [
            'transaction',
            'stock_movement',
            'dossier_club',
            'licencie',
            'dirigeant',
            'stock_item',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->connection->executeStatement('DELETE FROM ' . $table);
        }

        return $counts;
    }
}

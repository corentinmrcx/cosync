<?php declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class PurgeService
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Vide toutes les données métier de la base, dans une transaction (tout ou rien).
     *
     * Conserve uniquement les référentiels requis par l'application :
     *   - user     (comptes admin)
     *   - category (catégories FFF U6→SENIOR)
     *
     * Les tables sont supprimées des feuilles vers les racines pour respecter les
     * clés étrangères, quelle que soit leur règle ON DELETE.
     *
     * @return array<string, int> nombre de lignes supprimées par table
     */
    public function purgeAll(): array
    {
        // Ordre FK-safe : chaque table est vidée après toutes celles qui la référencent.
        $tables = [
            'transaction',
            'cle_mouvement',
            'commande_ligne',
            'dotation_modele_ligne',
            'dotation_affectation',
            'dotation_besoin',
            'stock_movement',
            'commande',
            'dotation_modele',
            'dossier_club',
            'licencie',
            'dirigeant',
            'stock_item',
            'fournisseur',
            'stock_category',
            'team',
            'season',
        ];

        /** @var array<string, int> $counts */
        $counts = $this->connection->transactional(function (Connection $connection) use ($tables): array {
            $deleted = [];
            foreach ($tables as $table) {
                $deleted[$table] = (int) $connection->executeStatement('DELETE FROM ' . $table);
            }

            return $deleted;
        });

        return $counts;
    }
}

<?php declare(strict_types=1);

namespace App\Service\Ops;

use App\Service\Pdf\PdfStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PurgeService
{
    /** Clé du décompte des fichiers, distincte de toute table pour rester lisible dans le rapport. */
    public const CLE_FICHIERS_PDF = 'fichiers PDF locaux';

    public function __construct(
        private readonly Connection $connection,
        private readonly PdfStorage $pdfStorage,
    ) {}

    /**
     * Vide toutes les données métier de la base, dans une transaction (tout ou rien),
     * puis supprime les PDF signés restés en local.
     *
     * Conserve uniquement les référentiels requis par l'application :
     *   - user          (comptes admin)
     *   - category      (catégories FFF U6→SENIOR)
     *   - club_settings (RIB du club : réglage, pas donnée de test)
     *
     * Les tables sont supprimées des feuilles vers les racines pour respecter les
     * clés étrangères, quelle que soit leur règle ON DELETE.
     *
     * @return array<string, int> nombre de lignes supprimées par table, plus les fichiers
     */
    public function purgeAll(): array
    {
        // Ordre FK-safe : chaque table est vidée après toutes celles qui la référencent.
        $tables = [
            'transaction',
            'document_signature',
            'document_signable_dirigeant',
            'document_signable',
            'attestation_cle',
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
            'detenteur',
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

            // Sans cela, la base « vide » repartirait aux identifiants de la campagne
            // précédente : un jeu de test relancé donnerait un premier licencié n° 65.
            // Les tables de liaison (pas de colonne id) sont écartées par la requête.
            $sequences = $connection->fetchFirstColumn(
                'SELECT pg_get_serial_sequence(table_name, \'id\')
                   FROM information_schema.columns
                  WHERE table_schema = current_schema()
                    AND column_name = \'id\'
                    AND table_name IN (?)',
                [$tables],
                [ArrayParameterType::STRING],
            );

            foreach (array_filter($sequences) as $sequence) {
                $connection->executeStatement('SELECT setval(CAST(? AS regclass), 1, false)', [$sequence]);
            }

            return $deleted;
        });

        // Après le commit seulement : si la transaction échoue, les fichiers restent la
        // seule copie des signatures dont les lignes existent encore.
        $counts[self::CLE_FICHIERS_PDF] = $this->pdfStorage->viderRepertoire();

        return $counts;
    }
}

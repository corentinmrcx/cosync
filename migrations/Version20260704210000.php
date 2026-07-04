<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prérequis à l'import « Licences dématérialisées » :
 *  - ajoute la catégorie FFF « Foot Loisir » ;
 *  - resynchronise la séquence d'identité de dirigeant_role (une base ayant subi un
 *    doctrine:schema:update peut avoir une séquence orpheline dirigeant_role_id_seq1
 *    désynchronisée, ce qui fait échouer la création de rôles pendant l'import).
 */
final class Version20260704210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégorie Foot Loisir + resynchronisation de la séquence dirigeant_role.';
    }

    public function up(Schema $schema): void
    {
        // 1. Nouvelle catégorie FFF « Foot Loisir » (idempotent).
        $this->addSql(<<<'SQL'
            INSERT INTO category (code, label, is_ecole_foot)
            SELECT 'FOOTLOISIR', 'Foot Loisir', false
            WHERE NOT EXISTS (SELECT 1 FROM category WHERE code = 'FOOTLOISIR')
            SQL);

        // 2. Resynchronise la séquence d'identité possédée par la colonne.
        $this->addSql(<<<'SQL'
            SELECT setval(
                pg_get_serial_sequence('dirigeant_role', 'id'),
                GREATEST((SELECT COALESCE(MAX(id), 1) FROM dirigeant_role), 1)
            )
            SQL);

        // 3. Corrige la séquence orpheline si elle existe (bases passées par schema:update).
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_sequences WHERE sequencename = 'dirigeant_role_id_seq1') THEN
                    PERFORM setval(
                        'dirigeant_role_id_seq1',
                        GREATEST((SELECT COALESCE(MAX(id), 1) FROM dirigeant_role), 1)
                    );
                END IF;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        // La resynchronisation de séquence n'est pas réversible (et ne doit pas l'être).
        $this->addSql("DELETE FROM category WHERE code = 'FOOTLOISIR'");
    }
}

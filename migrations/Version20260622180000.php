<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create dirigeant_role table with default roles, migrate dirigeant.role string to FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dirigeant_role (id SERIAL NOT NULL, label VARCHAR(100) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_dirigeant_role_label ON dirigeant_role (label)');

        // Rôles par défaut
        $this->addSql("INSERT INTO dirigeant_role (label, sort_order) VALUES
            ('Président', 10),
            ('Vice-Président', 20),
            ('Secrétaire', 30),
            ('Trésorier', 40),
            ('Éducateur', 50),
            ('Éducateur adjoint', 60),
            ('Arbitre', 70),
            ('Délégué', 80),
            ('Accompagnateur', 90)
        ");

        // Ajouter la colonne FK
        $this->addSql('ALTER TABLE dirigeant ADD role_id INT DEFAULT NULL');

        // Migrer les valeurs existantes (correspondance exacte)
        $this->addSql('UPDATE dirigeant SET role_id = (SELECT id FROM dirigeant_role WHERE label = dirigeant.role) WHERE dirigeant.role IS NOT NULL');

        // Pour les rôles sans correspondance exacte, créer le rôle à la volée
        $this->addSql("
            INSERT INTO dirigeant_role (label, sort_order)
            SELECT DISTINCT d.role, 100
            FROM dirigeant d
            WHERE d.role IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM dirigeant_role r WHERE r.label = d.role)
        ");
        $this->addSql('UPDATE dirigeant SET role_id = (SELECT id FROM dirigeant_role WHERE label = dirigeant.role) WHERE dirigeant.role IS NOT NULL AND role_id IS NULL');

        // Supprimer l'ancienne colonne et ajouter la contrainte FK
        $this->addSql('ALTER TABLE dirigeant DROP COLUMN role');
        $this->addSql('ALTER TABLE dirigeant ADD CONSTRAINT FK_dirigeant_role FOREIGN KEY (role_id) REFERENCES dirigeant_role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_dirigeant_role_id ON dirigeant (role_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant DROP CONSTRAINT FK_dirigeant_role');
        $this->addSql('DROP INDEX IDX_dirigeant_role_id');
        $this->addSql('ALTER TABLE dirigeant ADD role VARCHAR(100) DEFAULT NULL');
        $this->addSql('UPDATE dirigeant SET role = (SELECT label FROM dirigeant_role WHERE id = dirigeant.role_id) WHERE dirigeant.role_id IS NOT NULL');
        $this->addSql('ALTER TABLE dirigeant DROP COLUMN role_id');
        $this->addSql('DROP TABLE dirigeant_role');
    }
}

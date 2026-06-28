<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team : remplace defaultCategory (ManyToOne) par categories (ManyToMany via team_category)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE team_category (
            team_id     INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (team_id, category_id)
        )');
        $this->addSql('CREATE INDEX idx_team_category_team     ON team_category (team_id)');
        $this->addSql('CREATE INDEX idx_team_category_category ON team_category (category_id)');
        $this->addSql('ALTER TABLE team_category
            ADD CONSTRAINT fk_team_category_team     FOREIGN KEY (team_id)     REFERENCES team(id)     ON DELETE CASCADE,
            ADD CONSTRAINT fk_team_category_category FOREIGN KEY (category_id) REFERENCES category(id) ON DELETE CASCADE
        ');

        // Migration des données existantes
        $this->addSql('INSERT INTO team_category (team_id, category_id)
            SELECT id, default_category_id FROM team WHERE default_category_id IS NOT NULL
        ');

        $this->addSql('ALTER TABLE team DROP COLUMN default_category_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD COLUMN default_category_id INT DEFAULT NULL');
        $this->addSql('UPDATE team t SET default_category_id = (
            SELECT category_id FROM team_category tc WHERE tc.team_id = t.id LIMIT 1
        )');
        $this->addSql('ALTER TABLE team
            ADD CONSTRAINT fk_team_default_category FOREIGN KEY (default_category_id) REFERENCES category(id) ON DELETE SET NULL
        ');
        $this->addSql('ALTER TABLE team_category DROP CONSTRAINT fk_team_category_team');
        $this->addSql('ALTER TABLE team_category DROP CONSTRAINT fk_team_category_category');
        $this->addSql('DROP TABLE team_category');
    }
}

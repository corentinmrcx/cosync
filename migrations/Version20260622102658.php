<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260622102658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dirigeant (uuid UUID NOT NULL, num_licence VARCHAR(50) DEFAULT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, date_naissance DATE DEFAULT NULL, role VARCHAR(100) DEFAULT NULL, taille_haut VARCHAR(20) DEFAULT NULL, taille_bas VARCHAR(20) DEFAULT NULL, pointure VARCHAR(5) DEFAULT NULL, created_manually BOOLEAN DEFAULT false NOT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, form_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, form_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, team_id INT DEFAULT NULL, season_id INT NOT NULL, licencie_id UUID DEFAULT NULL, PRIMARY KEY (uuid))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BEC71E71D8A9FCA1 ON dirigeant (num_licence)');
        $this->addSql('CREATE INDEX IDX_BEC71E71296CD8AE ON dirigeant (team_id)');
        $this->addSql('CREATE INDEX IDX_BEC71E714EC001D1 ON dirigeant (season_id)');
        $this->addSql('CREATE INDEX IDX_BEC71E71B56DCD74 ON dirigeant (licencie_id)');
        $this->addSql('ALTER TABLE dirigeant ADD CONSTRAINT FK_BEC71E71296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dirigeant ADD CONSTRAINT FK_BEC71E714EC001D1 FOREIGN KEY (season_id) REFERENCES season (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dirigeant ADD CONSTRAINT FK_BEC71E71B56DCD74 FOREIGN KEY (licencie_id) REFERENCES licencie (uuid) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD dirigeant_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5E233AF25 FOREIGN KEY (dirigeant_id) REFERENCES dirigeant (uuid) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BB1BC1B5E233AF25 ON stock_movement (dirigeant_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dirigeant DROP CONSTRAINT FK_BEC71E71296CD8AE');
        $this->addSql('ALTER TABLE dirigeant DROP CONSTRAINT FK_BEC71E714EC001D1');
        $this->addSql('ALTER TABLE dirigeant DROP CONSTRAINT FK_BEC71E71B56DCD74');
        $this->addSql('DROP TABLE dirigeant');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5E233AF25');
        $this->addSql('DROP INDEX IDX_BB1BC1B5E233AF25');
        $this->addSql('ALTER TABLE stock_movement DROP dirigeant_id');
    }
}

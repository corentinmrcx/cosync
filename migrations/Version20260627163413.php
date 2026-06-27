<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260627163413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_club ADD autorisation_accident BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_club ADD volontaire_transport BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_club ADD attestation_transport_drive_id VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_club DROP autorisation_accident');
        $this->addSql('ALTER TABLE dossier_club DROP volontaire_transport');
        $this->addSql('ALTER TABLE dossier_club DROP attestation_transport_drive_id');
    }
}

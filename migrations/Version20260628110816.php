<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628110816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dirigeant : droit à l\'image, transport des licenciés et attestation transport';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dirigeant ADD autorisation_photo BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD volontaire_transport BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD attestation_transport_drive_id VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dirigeant DROP autorisation_photo');
        $this->addSql('ALTER TABLE dirigeant DROP volontaire_transport');
        $this->addSql('ALTER TABLE dirigeant DROP attestation_transport_drive_id');
    }
}

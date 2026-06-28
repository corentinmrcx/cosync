<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626221135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dossier_club ADD payment_intentions JSON NOT NULL DEFAULT '[]'");
        $this->addSql("UPDATE dossier_club SET payment_intentions = CASE WHEN payment_intention IS NULL THEN '[]'::json ELSE json_build_array(payment_intention::text)::json END");
        $this->addSql('ALTER TABLE dossier_club DROP payment_intention');
        $this->addSql('ALTER TABLE transaction ADD note VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_club ADD payment_intention VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE dossier_club SET payment_intention = (payment_intentions->>0) WHERE jsonb_array_length(payment_intentions::jsonb) > 0");
        $this->addSql('ALTER TABLE dossier_club DROP payment_intentions');
        $this->addSql('ALTER TABLE transaction DROP note');
    }
}

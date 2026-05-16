<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516145230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE licencie ADD voie_rue VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE licencie ADD code_postal VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE licencie ADD ville VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE licencie DROP voie_rue');
        $this->addSql('ALTER TABLE licencie DROP code_postal');
        $this->addSql('ALTER TABLE licencie DROP ville');
    }
}

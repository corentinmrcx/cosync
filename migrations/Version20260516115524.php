<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516115524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category ALTER min_year DROP NOT NULL');
        $this->addSql('ALTER TABLE category ALTER max_year DROP NOT NULL');
        $this->addSql('ALTER TABLE licencie ALTER email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category ALTER min_year SET NOT NULL');
        $this->addSql('ALTER TABLE category ALTER max_year SET NOT NULL');
        $this->addSql('ALTER TABLE licencie ALTER email SET NOT NULL');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608131202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_item ADD marque VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD taille VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD type_vetement VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD prix_achat NUMERIC(8, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_item DROP marque');
        $this->addSql('ALTER TABLE stock_item DROP taille');
        $this->addSql('ALTER TABLE stock_item DROP type_vetement');
        $this->addSql('ALTER TABLE stock_item DROP prix_achat');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Archivage doux des articles stock (stock_item.actif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD actif BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP actif');
    }
}

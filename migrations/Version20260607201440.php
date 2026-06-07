<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607201440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reglement_text to season';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD reglement_text TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP COLUMN reglement_text');
    }
}

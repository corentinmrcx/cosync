<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add form_token_expires_at and form_completed_at to dirigeant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant ADD form_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD form_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant DROP form_token_expires_at');
        $this->addSql('ALTER TABLE dirigeant DROP form_completed_at');
    }
}

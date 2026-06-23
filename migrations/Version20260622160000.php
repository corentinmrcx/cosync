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
        // colonnes déjà présentes dans la migration initiale Version20260516100518
    }

    public function down(Schema $schema): void
    {
    }
}

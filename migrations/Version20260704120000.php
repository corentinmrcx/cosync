<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signature du règlement intérieur par les dirigeants (reglement_signe_path + reglement_signed_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant ADD reglement_signe_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD reglement_signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant DROP reglement_signe_path');
        $this->addSql('ALTER TABLE dirigeant DROP reglement_signed_at');
    }
}

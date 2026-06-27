<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260627132059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_club ALTER payment_intentions DROP DEFAULT');
        $this->addSql('ALTER INDEX idx_team_category_team RENAME TO IDX_BE0EC5BF296CD8AE');
        $this->addSql('ALTER INDEX idx_team_category_category RENAME TO IDX_BE0EC5BF12469DE2');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_club ALTER payment_intentions SET DEFAULT \'[]\'');
        $this->addSql('ALTER INDEX idx_be0ec5bf12469de2 RENAME TO idx_team_category_category');
        $this->addSql('ALTER INDEX idx_be0ec5bf296cd8ae RENAME TO idx_team_category_team');
    }
}

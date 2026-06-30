<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629095508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute dotation_besoin.taille_manuelle (taille fixée à la main, préservée au recalcul).';
    }

    public function up(Schema $schema): void
    {
        // Défaut le temps de remplir les lignes existantes, puis on le retire (le défaut vit dans l'entité).
        $this->addSql('ALTER TABLE dotation_besoin ADD taille_manuelle BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE dotation_besoin ALTER taille_manuelle DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dotation_besoin DROP taille_manuelle');
    }
}

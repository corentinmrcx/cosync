<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cotisation par équipe : team.cotisation (optionnelle) + season.cotisation_defaut
 * remplace season.base_costs (jeunes/seniors).
 */
final class Version20260629140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotisation par équipe (team.cotisation) + cotisation par défaut de la saison ; retrait de base_costs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD cotisation INT DEFAULT NULL');

        // Défaut temporaire pour les lignes existantes, repris depuis l'ancien base_costs->jeunes.
        $this->addSql('ALTER TABLE season ADD cotisation_defaut INT NOT NULL DEFAULT 0');
        $this->addSql("UPDATE season SET cotisation_defaut = COALESCE((base_costs->>'jeunes')::int, 0)");
        $this->addSql('ALTER TABLE season ALTER cotisation_defaut DROP DEFAULT');
        $this->addSql('ALTER TABLE season DROP base_costs');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD base_costs JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql("UPDATE season SET base_costs = json_build_object('jeunes', cotisation_defaut, 'seniors', cotisation_defaut)");
        $this->addSql('ALTER TABLE season ALTER base_costs DROP DEFAULT');
        $this->addSql('ALTER TABLE season DROP cotisation_defaut');
        $this->addSql('ALTER TABLE team DROP cotisation');
    }
}

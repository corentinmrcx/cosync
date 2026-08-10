<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Règlement intérieur propre aux dirigeants.
 *
 * Jusqu'ici les dirigeants signaient season.reglement_text, c'est-à-dire le
 * règlement des joueurs. Cette colonne porte le texte qui leur est destiné ;
 * les deux documents coexistent et sont édités séparément.
 *
 * ADD COLUMN nullable sans NOT NULL : PostgreSQL l'ajoute en métadonnée seule,
 * aucun backfill n'est nécessaire (NULL = règlement dirigeants pas encore rédigé,
 * le parcours affiche alors le message « contactez le club » déjà en place).
 * Les règlements déjà signés ne sont pas touchés.
 */
final class Version20260801140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de season.reglement_dirigeant_text : règlement intérieur distinct pour les dirigeants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD reglement_dirigeant_text TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP reglement_dirigeant_text');
    }
}

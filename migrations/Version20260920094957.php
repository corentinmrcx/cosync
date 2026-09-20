<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'article réellement remis quand ce n'est pas celui du kit.
 *
 * Colonne nullable, sans reprise : les lignes déjà remises l'ont été avec l'article prévu,
 * c'est bien `NULL` qu'elles doivent porter.
 *
 * Le diff d'entités portait aussi de la dérive sans rapport (colonnes restées en base après
 * un retrait d'entité) : elle n'entre pas ici. Une migration = une feature, et chacun de ces
 * `DROP` est une perte de données à annoncer pour elle-même.
 */
final class Version20260920094957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dotation : article réellement remis quand il diffère de celui du kit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_besoin ADD article_remis_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dotation_besoin ADD CONSTRAINT FK_CC970C16C7F3C445 FOREIGN KEY (article_remis_id) REFERENCES stock_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CC970C16C7F3C445 ON dotation_besoin (article_remis_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_besoin DROP CONSTRAINT FK_CC970C16C7F3C445');
        $this->addSql('DROP INDEX IDX_CC970C16C7F3C445');
        $this->addSql('ALTER TABLE dotation_besoin DROP article_remis_id');
    }
}

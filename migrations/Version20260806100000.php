<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Éligibilité d'une ligne de dotation selon la nature de la licence.
 *
 * Permet de réserver une option à une population (« la veste est imposée aux nouveaux
 * licenciés ») sans dupliquer le kit entier.
 *
 * NOT NULL assorti d'un DEFAULT : PostgreSQL 11+ le pose en métadonnée seule, sans
 * réécriture de table ni backfill explicite. Toutes les lignes déjà configurées deviennent
 * « tous », ce qui reproduit exactement le comportement actuel.
 */
final class Version20260806100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éligibilité (tous / nouveaux / renouvellements) sur les lignes de modèle de dotation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dotation_modele_ligne ADD eligibilite VARCHAR(20) DEFAULT 'tous' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_modele_ligne DROP eligibilite');
    }
}

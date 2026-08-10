<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nature de la licence (nouveau licencié vs renouvellement).
 *
 * 1. licencie.nature_licence : valeur de la colonne « Nature » de l'export FootClubs
 *    (nouvelle_demande / changement_club / renouvellement). Nullable : les licenciés déjà
 *    en base ont été importés avant que cette colonne soit lue, leur nature est inconnue
 *    et devra être renseignée par un réimport ou à la main. Aucun backfill possible ici :
 *    la donnée n'existe nulle part dans la base actuelle.
 *
 * 2. licencie.nature_manuelle : marque une nature corrigée par l'admin, qu'un réimport ne
 *    doit plus écraser. DEFAULT FALSE, donc le NOT NULL passe sans backfill séparé.
 */
final class Version20260806090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nature de la licence sur le licencié (nouveau vs renouvellement) + verrou de correction manuelle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE licencie ADD nature_licence VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE licencie ADD nature_manuelle BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE licencie DROP nature_manuelle');
        $this->addSql('ALTER TABLE licencie DROP nature_licence');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validation FootClubs : date à laquelle le club a signé la licence d'un dirigeant.
 *
 * Deux précisions pour la relecture :
 *
 * - **Colonne nullable, sans backfill.** `NULL` veut dire « pas encore validée », ce qui est
 *   vrai de tout l'existant : personne n'a jamais pu poser cette information.
 * - **Rien pour les joueurs.** Le nouveau statut `a_valider_fff` de `dossier_club.status` est
 *   une valeur d'enum PHP dans une colonne texte : aucun DDL. Et **aucun UPDATE** — décision
 *   prise avec le club : les dossiers déjà en `validated` le restent, le nouveau geste ne
 *   concerne que les licences soldées à partir d'ici. Ce n'est donc pas un backfill oublié.
 *
 * Le diff auto-généré proposait en prime de supprimer des colonnes dé-mappées
 * (`cle_mouvement.season_id`, `dirigeant.attestation_cle_*`, `season.iban`…) : elles portent
 * des données réelles conservées le temps de valider leurs bascules, elles ne sont pas
 * reprises ici.
 */
final class Version20260828211444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute dirigeant.validated_fff_at (validation de la licence dans FootClubs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant ADD validated_fff_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant DROP validated_fff_at');
    }
}

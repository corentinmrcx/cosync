<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute dossier_club.helloasso_checkout_started_at : date de création de l'intention
 * de paiement, qui borne la réconciliation app:helloasso:sync-paiements.
 *
 * Migration volontairement réduite à cet ajout. L'auto-génération proposait en plus :
 *   - un RENAME de signature_date vers cette nouvelle colonne (recyclage d'une colonne
 *     contenant de vraies dates de signature — destructif et faux),
 *   - le DROP des colonnes dé-mappées dirigeant.reglement_signe_path / reglement_signed_at,
 *     dossier_club.is_signed / signature_path, season.reglement_text / reglement_dirigeant_text.
 * Ces DROP sont des pertes de données définitives : ils feront l'objet d'une migration
 * dédiée, après vérification que plus rien n'en dépend (cf. CLAUDE.md §13).
 */
final class Version20260808200847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute dossier_club.helloasso_checkout_started_at (borne de réconciliation HelloAsso)';
    }

    public function up(Schema $schema): void
    {
        // Colonne nullable : aucun backfill nécessaire, les lignes existantes restent
        // réconciliables (la requête traite NULL comme « à interroger »).
        $this->addSql('ALTER TABLE dossier_club ADD helloasso_checkout_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN dossier_club.helloasso_checkout_started_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_club DROP helloasso_checkout_started_at');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coordonnées bancaires du club portées par la saison (iban / bic / titulaire_compte).
 *
 * Pattern expand + backfill (CLAUDE.md §13) : colonnes nullables, puis reprise des valeurs
 * jusqu'ici codées en dur dans les templates, pour que le comportement soit strictement
 * identique après déploiement. Aucun passage en NOT NULL : une saison sans IBAN est
 * légitime — elle masque simplement l'option « virement ».
 *
 * Migration volontairement réduite à cet ajout. L'auto-génération proposait en plus le DROP
 * de dirigeant.reglement_signe_path / reglement_signed_at, dossier_club.is_signed /
 * signature_path / signature_date et season.reglement_text / reglement_dirigeant_text.
 * Ce sont des pertes de données définitives, sur des colonnes dé-mappées mais encore
 * remplies : elles feront l'objet d'une migration dédiée, après vérification.
 */
final class Version20260808213640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les coordonnées bancaires du club sur la saison (iban, bic, titulaire)';
    }

    public function up(Schema $schema): void
    {
        // Expand
        $this->addSql('ALTER TABLE season ADD iban VARCHAR(34) DEFAULT NULL');
        $this->addSql('ALTER TABLE season ADD bic VARCHAR(11) DEFAULT NULL');
        $this->addSql('ALTER TABLE season ADD titulaire_compte VARCHAR(100) DEFAULT NULL');

        // Backfill : valeurs reprises de templates/public/inscription/{form,confirmation}.html.twig
        $this->addSql(<<<'SQL'
            UPDATE season
               SET iban             = 'FR76 1020 6000 2020 1001 5900 056',
                   bic              = 'AGRIFRPP802',
                   titulaire_compte = 'Foyer de Soudron'
             WHERE iban IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP iban');
        $this->addSql('ALTER TABLE season DROP bic');
        $this->addSql('ALTER TABLE season DROP titulaire_compte');
    }
}

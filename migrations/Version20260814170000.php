<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sépare l'ouverture de la boutique de la saisie de son lien, et garde la trace de son
 * annonce par licencié.
 *
 * Le club lance ses licences d'abord et sa boutique quelques jours plus tard : jusqu'ici
 * le seul moyen de la taire était de laisser le lien vide, donc de ne pas le préparer. Et
 * l'annonce partait d'elle-même à chaque soumission de formulaire — ceux qui s'étaient
 * inscrits avant l'ouverture n'étaient jamais rattrapés.
 *
 * NON DESTRUCTIF — deux colonnes ajoutées, rien n'est supprimé :
 *  - `club_settings.boutique_ouverte` est backfillée à VRAI si un lien est déjà saisi, pour
 *    qu'une boutique déjà annoncée en production ne se referme pas à l'insu de l'admin ;
 *  - `licencie.boutique_annoncee_at` est backfillée pour les dossiers déjà complétés, mais
 *    seulement si un lien existait : ces licenciés ont reçu l'annonce automatique, il ne
 *    faut pas la leur renvoyer au premier envoi groupé.
 */
final class Version20260814170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute club_settings.boutique_ouverte et licencie.boutique_annoncee_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_settings ADD boutique_ouverte BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('UPDATE club_settings SET boutique_ouverte = TRUE WHERE boutique_url IS NOT NULL');

        $this->addSql('ALTER TABLE licencie ADD boutique_annoncee_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE licencie
               SET boutique_annoncee_at = d.form_completed_at
              FROM dossier_club d
             WHERE d.licencie_id = licencie.uuid
               AND d.form_completed_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM club_settings WHERE boutique_url IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Perte de l'état d'ouverture et de la trace des annonces déjà envoyées : sans elle,
        // un envoi groupé écrirait une seconde fois à tout un effectif.
        $this->addSql('ALTER TABLE licencie DROP boutique_annoncee_at');
        $this->addSql('ALTER TABLE club_settings DROP boutique_ouverte');
    }
}

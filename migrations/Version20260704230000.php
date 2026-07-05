<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduit le statut « Importé » : un dossier « Lien envoyé » dont le lien n'est jamais
 * réellement parti (link_sent_at NULL) est en fait seulement importé. On rétablit la cohérence
 * sur l'existant (notamment le roster importé via « Licences dématérialisées »).
 */
final class Version20260704230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill du statut Importé pour les dossiers dont le lien n\'a jamais été envoyé.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE dossier_club dc
            SET status = 'imported'
            FROM licencie l
            WHERE dc.licencie_id = l.uuid
              AND dc.status = 'link_sent'
              AND l.link_sent_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Retour best-effort : les dossiers « importés » redeviennent « lien envoyé ».
        $this->addSql("UPDATE dossier_club SET status = 'link_sent' WHERE status = 'imported'");
    }
}

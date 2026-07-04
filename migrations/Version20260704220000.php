<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trace l'envoi effectif du lien d'inscription (link_sent_at) pour distinguer un licencié
 * simplement importé d'un licencié à qui le mail est réellement parti. Rempli uniquement lors
 * d'un envoi réussi ; laissé NULL sur les imports « Licences dématérialisées » (sans envoi auto).
 */
final class Version20260704220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de licencie.link_sent_at (date d\'envoi effectif du lien d\'inscription).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE licencie ADD link_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE licencie DROP link_sent_at');
    }
}

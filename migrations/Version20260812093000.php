<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le lien de la boutique du club aux réglages de l'association.
 *
 * La boutique (HelloAsso) est une page du club, pas de la saison : elle vit donc dans
 * club_settings, à côté du RIB, et non dans `season`.
 *
 * NON DESTRUCTIF — colonne nullable, sans backfill : tant qu'aucun lien n'est saisi,
 * la boutique n'est annoncée ni sur la page de confirmation ni par mail.
 */
final class Version20260812093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute club_settings.boutique_url (lien de la boutique du club)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_settings ADD boutique_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Perte du lien saisi en administration — il se re-saisit en une minute.
        $this->addSql('ALTER TABLE club_settings DROP boutique_url');
    }
}

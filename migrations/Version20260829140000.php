<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réglages de la relance automatique des licences non soldées.
 *
 * NON DESTRUCTIF. Trois colonnes ajoutées à `club_settings`, une table à ligne unique déjà
 * remplie : elles sont donc NOT NULL **avec DEFAULT**, la seule forme qui passe sur une
 * table non vide (cf. §13).
 *
 * `relance_active` vaut `false` : la migration installe le robot, elle ne l'allume pas.
 * Un automate qui écrit à tout un effectif ne doit jamais démarrer d'un déploiement — il
 * démarre d'une décision, prise dans /admin/club/relances, après un `--dry-run`.
 */
final class Version20260829140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les réglages de relance automatique (interrupteur, délai, plafond)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_settings ADD relance_active BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE club_settings ADD relance_delai_jours INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE club_settings ADD relance_max INT DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // DESTRUCTIF au sens strict : les trois réglages sont perdus. Sans conséquence
        // fonctionnelle — ils reprennent leurs valeurs par défaut, robot éteint.
        $this->addSql('ALTER TABLE club_settings DROP relance_active');
        $this->addSql('ALTER TABLE club_settings DROP relance_delai_jours');
        $this->addSql('ALTER TABLE club_settings DROP relance_max');
    }
}

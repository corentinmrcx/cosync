<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marque les licences dirigeantes déclarées au district pour raison légale (président,
 * secrétaire, trésorier) : ces personnes ne signent rien, ne reçoivent aucun lien de
 * formulaire et n'ont droit à aucune dotation.
 *
 * NON DESTRUCTIF — une seule colonne booléenne, `DEFAULT false` : toutes les lignes
 * existantes conservent exactement le comportement actuel. Aucun backfill : c'est à
 * l'admin de désigner les licences concernées, le club est seul à savoir lesquelles.
 */
final class Version20260816090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le drapeau « licence administrative » sur les dirigeants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant ADD licence_administrative BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Perte de données : les licences marquées administratives redeviendraient des
        // dirigeants ordinaires, et leur kit se matérialiserait au prochain recalcul.
        $this->addSql('ALTER TABLE dirigeant DROP licence_administrative');
    }
}

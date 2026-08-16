<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Verrou de correction manuelle sur les coordonnées d'un licencié et d'un dirigeant.
 *
 * Une adresse mail fausse dans FootClubs ne se corrige pas toujours le jour même — un dossier
 * en cours de validation à la ligue interdit d'y toucher. Sans verrou, l'admin corrigeait dans
 * CoSync et le prochain import ramenait la faute sans rien dire ; le lien d'inscription
 * repartait à la mauvaise adresse.
 *
 * NON DESTRUCTIF — quatre booléens avec DEFAULT false : au départ aucun champ n'est verrouillé,
 * l'import se comporte donc exactement comme avant sur toutes les lignes existantes.
 */
final class Version20260816150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le verrou « corrigé à la main » sur l\'email et le téléphone (licenciés et dirigeants)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE licencie ADD email_manuel BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE licencie ADD telephone_manuel BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('ALTER TABLE dirigeant ADD email_manuel BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD telephone_manuel BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Perte de données : on oublie quelles coordonnées avaient été corrigées à la main.
        // Les valeurs corrigées restent en base, mais le prochain import les réécrasera.
        $this->addSql('ALTER TABLE dirigeant DROP telephone_manuel');
        $this->addSql('ALTER TABLE dirigeant DROP email_manuel');

        $this->addSql('ALTER TABLE licencie DROP telephone_manuel');
        $this->addSql('ALTER TABLE licencie DROP email_manuel');
    }
}

<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute dirigeant.link_sent_at : la date à laquelle le lien du formulaire est parti.
 *
 * Jusqu'ici cette date se déduisait de `form_token_expires_at` moins la fenêtre de 30 jours,
 * approximation qui ne tient plus : le jeton est remis à null dès le dossier complet, et
 * l'écran d'envoi groupé a besoin de savoir qui n'a **jamais** été contacté — pas qui n'a
 * pas de jeton en cours.
 *
 * NON DESTRUCTIF — colonne nullable, suivie d'un backfill qui ne remplit que des lignes
 * dont on sait qu'un lien est parti :
 *   - jeton encore posé (même expiré) → envoi = expiration − 30 jours, valeur exacte ;
 *   - jeton consommé mais formulaire complété → au plus tard la date de complétion, car
 *     le formulaire public ne s'ouvre que par le lien reçu.
 * Un dirigeant sans jeton ni formulaire complété reste à null : il n'a jamais rien reçu,
 * et c'est précisément lui que le nouvel écran doit proposer.
 */
final class Version20260814100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute dirigeant.link_sent_at et le renseigne pour les dirigeants déjà contactés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant ADD link_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN dirigeant.link_sent_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(
            "UPDATE dirigeant SET link_sent_at = form_token_expires_at - INTERVAL '30 days'
             WHERE form_token_expires_at IS NOT NULL"
        );
        $this->addSql(
            'UPDATE dirigeant SET link_sent_at = form_completed_at
             WHERE link_sent_at IS NULL AND form_completed_at IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Perte des dates d'envoi — la chronologie des fiches dirigeants repasserait à
        // la date déduite du jeton, sans plus rien savoir des liens déjà consommés.
        $this->addSql('ALTER TABLE dirigeant DROP link_sent_at');
    }
}

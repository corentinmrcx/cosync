<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CONTRACT (3/3) — ferme la bascule du registre des clés au niveau du club.
 *
 * Pose ce que le backfill (Version20260810120100) rend possible : `detenteur_id`
 * obligatoire et sa clé étrangère. La migration commence par vérifier que la reprise
 * est complète — s'il manque un seul rattachement, elle s'interrompt sans rien
 * modifier, PostgreSQL exécutant le DDL en transaction.
 *
 * NON DESTRUCTIF, volontairement. Trois colonnes deviennent mortes sans être
 * supprimées, et perdent seulement leur NOT NULL pour que l'application puisse
 * continuer d'écrire :
 *
 *   cle_mouvement.dirigeant_id       → cle_mouvement.detenteur_id
 *   cle_mouvement.season_id          → plus rien : une clé remise en janvier est
 *                                      toujours dehors en septembre
 *   dirigeant.attestation_cle_*      → attestation_cle (une ligne par saison)
 *
 * Elles restent le filet de sécurité de la bascule : tant qu'elles existent, le
 * backfill peut être rejoué après correction d'un rapprochement erroné. Leur
 * suppression fera l'objet d'une migration séparée, une fois la bascule validée sur
 * les vraies données — et sera, elle, destructive.
 */
final class Version20260810120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CONTRACT : rend cle_mouvement.detenteur_id obligatoire et libère les colonnes remplacées.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfRepriseIncomplete();

        $this->addSql('ALTER TABLE cle_mouvement ALTER detenteur_id SET NOT NULL');
        $this->addSql('ALTER TABLE cle_mouvement ADD CONSTRAINT FK_45D1DEB4742F89B9 FOREIGN KEY (detenteur_id) REFERENCES detenteur (id) NOT DEFERRABLE');

        // Ces deux colonnes ne sont plus mappées : sans cette levée du NOT NULL,
        // le premier mouvement de clé enregistré après le déploiement échouerait.
        $this->addSql('ALTER TABLE cle_mouvement ALTER dirigeant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE cle_mouvement ALTER season_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cle_mouvement DROP CONSTRAINT FK_45D1DEB4742F89B9');
        $this->addSql('ALTER TABLE cle_mouvement ALTER detenteur_id DROP NOT NULL');

        // Le NOT NULL d'origine n'est remis que si aucune ligne n'a été écrite depuis
        // la bascule — une remise enregistrée entre-temps n'a ni dirigeant ni saison.
        $this->addSql('UPDATE cle_mouvement SET season_id = (SELECT MIN(id) FROM season) WHERE season_id IS NULL');
        $this->addSql('ALTER TABLE cle_mouvement ALTER season_id SET NOT NULL');
    }

    /**
     * Refuse de poser la contrainte si une donnée historique n'a pas été reprise.
     * Mieux vaut une migration qui s'arrête qu'un mouvement de clé orphelin ou une
     * attestation signée qui disparaît de l'écran.
     */
    private function abortIfRepriseIncomplete(): void
    {
        $controles = [
            'des mouvements de clé n\'ont pas été rattachés à un détenteur' => <<<'SQL'
                SELECT count(*) FROM cle_mouvement WHERE detenteur_id IS NULL
                SQL,

            'des attestations de clés signées n\'ont pas été reprises dans attestation_cle' => <<<'SQL'
                SELECT count(*) FROM dirigeant d
                WHERE d.attestation_cle_signe_path IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM attestation_cle a
                      WHERE a.season_id = d.season_id
                        AND a.drive_path = d.attestation_cle_signe_path
                  )
                SQL,

            'le total des clés en circulation a changé pendant la reprise' => <<<'SQL'
                SELECT CASE WHEN (
                    SELECT COALESCE(SUM(CASE WHEN type = 'remise' THEN quantite ELSE -quantite END), 0)
                    FROM cle_mouvement
                ) <> (
                    SELECT COALESCE(SUM(CASE WHEN type = 'remise' THEN quantite ELSE -quantite END), 0)
                    FROM cle_mouvement WHERE detenteur_id IS NOT NULL
                ) THEN 1 ELSE 0 END
                SQL,
        ];

        foreach ($controles as $probleme => $sql) {
            $ecarts = (int) $this->connection->fetchOne($sql);

            $this->abortIf($ecarts > 0, sprintf(
                'Reprise incomplète : %s (%d cas). Rejouer Version20260810120100 après correction — aucune donnée n\'a été modifiée.',
                $probleme,
                $ecarts,
            ));
        }
    }
}

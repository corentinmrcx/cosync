<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DESTRUCTIF — supprime les colonnes de signature et de règlement rendues obsolètes par
 * la bascule vers DocumentSignable / DocumentSignature (Version20260807233000).
 *
 * Colonnes supprimées, et où leur contenu vit désormais :
 *
 *   dossier_club.is_signed        → existence d'une ligne document_signature
 *   dossier_club.signature_path   → document_signature.drive_path
 *   dossier_club.signature_date   → document_signature.signed_at
 *   dirigeant.reglement_signe_path→ document_signature.drive_path
 *   dirigeant.reglement_signed_at → document_signature.signed_at
 *   season.reglement_text         → document_signable.contenu_html (code reglement_licencie)
 *   season.reglement_dirigeant_text → document_signable.contenu_html (code reglement_dirigeant)
 *
 * Ces colonnes étaient le filet de sécurité laissé par la bascule : tant qu'elles
 * existaient, un rollback restait possible. Les supprimer ferme cette porte —
 * la sauvegarde prise par `make prod-deploy` devient le seul recours.
 *
 * La migration commence par recompter les données historiques et vérifier que chacune
 * a bien son équivalent dans les nouvelles tables. Si un seul écart apparaît, elle
 * s'interrompt sans rien supprimer : PostgreSQL exécute le DDL en transaction.
 */
final class Version20260809103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DESTRUCTIF : supprime les colonnes de signature et de règlement remplacées par DocumentSignature';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfTransfertIncomplet();

        $this->addSql('ALTER TABLE dossier_club DROP is_signed');
        $this->addSql('ALTER TABLE dossier_club DROP signature_path');
        $this->addSql('ALTER TABLE dossier_club DROP signature_date');

        $this->addSql('ALTER TABLE dirigeant DROP reglement_signe_path');
        $this->addSql('ALTER TABLE dirigeant DROP reglement_signed_at');

        $this->addSql('ALTER TABLE season DROP reglement_text');
        $this->addSql('ALTER TABLE season DROP reglement_dirigeant_text');

        // Alignements sans perte, restés en attente derrière les DROP ci-dessus.
        $this->addSql('ALTER TABLE season ALTER cotisation_defaut SET DEFAULT 0');
        $this->addSql("COMMENT ON COLUMN dossier_club.helloasso_checkout_started_at IS ''");
    }

    /**
     * Recrée les colonnes, vides.
     *
     * Le contenu, lui, ne revient pas : les signatures et les textes de règlement vivent
     * désormais dans document_signature et document_signable, qui restent en place. Un
     * retour arrière complet suppose de restaurer la sauvegarde d'avant déploiement.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_club ADD is_signed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE dossier_club ADD signature_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_club ADD signature_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('ALTER TABLE dirigeant ADD reglement_signe_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD reglement_signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('ALTER TABLE season ADD reglement_text TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE season ADD reglement_dirigeant_text TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE season ALTER cotisation_defaut DROP DEFAULT');
    }

    /**
     * Refuse la suppression si une donnée historique n'a pas d'équivalent dans les
     * nouvelles tables. Mieux vaut une migration qui s'arrête qu'une signature perdue.
     */
    private function abortIfTransfertIncomplet(): void
    {
        $controles = [
            'des licenciés ont is_signed = true sans signature reprise dans document_signature' => <<<'SQL'
                SELECT count(*) FROM dossier_club dc
                JOIN licencie l ON l.uuid = dc.licencie_id
                WHERE dc.is_signed = true
                  AND NOT EXISTS (
                      SELECT 1 FROM document_signature ds
                      JOIN document_signable d ON d.id = ds.document_id
                      WHERE ds.licencie_uuid = l.uuid AND d.code = 'reglement_licencie'
                  )
                SQL,

            'des dirigeants ont un règlement signé sans reprise dans document_signature' => <<<'SQL'
                SELECT count(*) FROM dirigeant dg
                WHERE dg.reglement_signe_path IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM document_signature ds
                      JOIN document_signable d ON d.id = ds.document_id
                      WHERE ds.dirigeant_uuid = dg.uuid AND d.code = 'reglement_dirigeant'
                  )
                SQL,

            'un texte de règlement licencié n\'a pas été repris dans document_signable' => <<<'SQL'
                SELECT count(*) FROM season s
                WHERE COALESCE(s.reglement_text, '') <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM document_signable d
                      WHERE d.season_id = s.id AND d.code = 'reglement_licencie'
                        AND COALESCE(d.contenu_html, '') <> ''
                  )
                SQL,

            'un texte de règlement dirigeant n\'a pas été repris dans document_signable' => <<<'SQL'
                SELECT count(*) FROM season s
                WHERE COALESCE(s.reglement_dirigeant_text, '') <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM document_signable d
                      WHERE d.season_id = s.id AND d.code = 'reglement_dirigeant'
                        AND COALESCE(d.contenu_html, '') <> ''
                  )
                SQL,

            'un chemin de PDF signé n\'a pas été repris dans document_signature' => <<<'SQL'
                SELECT count(*) FROM dossier_club dc
                JOIN licencie l ON l.uuid = dc.licencie_id
                WHERE dc.signature_path IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM document_signature ds
                      WHERE ds.licencie_uuid = l.uuid AND ds.drive_path = dc.signature_path
                  )
                SQL,
        ];

        foreach ($controles as $probleme => $sql) {
            $ecarts = (int) $this->connection->fetchOne($sql);

            $this->abortIf($ecarts > 0, sprintf(
                'Suppression annulée : %s (%d cas). Reprenez la bascule avant de rejouer cette migration.',
                $probleme,
                $ecarts,
            ));
        }
    }
}

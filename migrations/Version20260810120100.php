<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BACKFILL (2/3) — reprend l'historique des clés dans les nouvelles tables.
 *
 * Trois reprises, dans cet ordre :
 *
 *   1. un `detenteur` par personne distincte ayant un mouvement de clé ou une
 *      attestation signée, toutes saisons confondues ;
 *   2. `cle_mouvement.detenteur_id` rattaché à cette personne ;
 *   3. une `attestation_cle` par attestation déjà signée, dans sa saison d'origine.
 *
 * Le dédoublonnage entre saisons se fait sur le numéro de licence, seul identifiant
 * stable ; à défaut, sur `nom + prénom` en minuscules. Une personne sans numéro de
 * licence dont l'orthographe change d'une saison à l'autre produira donc deux
 * détenteurs, et ses clés se retrouveront réparties sur deux lignes du registre.
 * Le volume étant de l'ordre de la dizaine, le contrôle est humain : restaurer un
 * dump de production en local, appliquer les trois migrations, relire la table
 * `detenteur` avant de déployer (cf. CLAUDE.md §13).
 *
 * Rien n'est supprimé ici : `cle_mouvement.dirigeant_id`, `cle_mouvement.season_id`
 * et les colonnes `dirigeant.attestation_cle_*` restent en place et gardent leur
 * contenu. C'est ce qui permet de rejouer ce backfill si le rapprochement se révèle
 * faux.
 */
final class Version20260810120100 extends AbstractMigration
{
    /**
     * Rapproche un dirigeant du détenteur créé pour lui. Écrit une fois, utilisé par
     * les deux reprises : le numéro de licence prime, le nom ne sert qu'aux personnes
     * qui n'en ont pas — et seulement face à un détenteur lui-même sans numéro, ce
     * qui rend le rapprochement non ambigu par construction.
     */
    private const JOINTURE_DETENTEUR = <<<'SQL'
        (
            (NULLIF(d.num_licence, '') IS NOT NULL AND det.num_licence = NULLIF(d.num_licence, ''))
            OR (
                NULLIF(d.num_licence, '') IS NULL
                AND det.num_licence IS NULL
                AND lower(det.nom) = lower(d.nom)
                AND lower(det.prenom) = lower(d.prenom)
            )
        )
        SQL;

    public function getDescription(): string
    {
        return 'BACKFILL : crée les détenteurs depuis l\'historique, rattache les mouvements et reprend les attestations signées.';
    }

    public function up(Schema $schema): void
    {
        $this->creerLesDetenteurs();
        $this->rattacherLesMouvements();
        $this->reprendreLesAttestationsSignees();
    }

    /**
     * Les données reprises ne sont pas détruites : ce down vide les tables neuves et
     * remet `detenteur_id` à NULL, l'original restant dans `dirigeant_id`.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE cle_mouvement SET detenteur_id = NULL');
        $this->addSql('DELETE FROM attestation_cle');
        $this->addSql('DELETE FROM detenteur');
    }

    /**
     * Une ligne par personne distincte. DISTINCT ON retient la saison la plus
     * récente : c'est là que le mail et le téléphone ont le plus de chances d'être
     * à jour, et c'est à cette adresse que partira la demande de signature.
     */
    private function creerLesDetenteurs(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO detenteur (nom, prenom, email, telephone, num_licence, created_at)
            SELECT DISTINCT ON (cle)
                   nom, prenom, email, telephone, NULLIF(num_licence, ''), NOW()
            FROM (
                SELECT d.nom,
                       d.prenom,
                       d.email,
                       d.telephone,
                       d.num_licence,
                       d.season_id,
                       COALESCE(NULLIF(d.num_licence, ''), lower(d.nom) || '|' || lower(d.prenom)) AS cle
                FROM dirigeant d
                WHERE EXISTS (SELECT 1 FROM cle_mouvement m WHERE m.dirigeant_id = d.uuid)
                   OR d.attestation_cle_signe_path IS NOT NULL
            ) candidats
            ORDER BY cle, season_id DESC
            SQL);
    }

    private function rattacherLesMouvements(): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE cle_mouvement m
            SET detenteur_id = det.id
            FROM dirigeant d, detenteur det
            WHERE m.dirigeant_id = d.uuid
              AND m.detenteur_id IS NULL
              AND %s
            SQL, self::JOINTURE_DETENTEUR));
    }

    /**
     * `nb_cles` est reconstitué depuis l'historique à la date de signature — c'est ce
     * que l'attestation disait. `remise_le` reste NULL : la date « détenteur depuis »
     * est le résultat d'un pli séquentiel sur les mouvements, pas d'un agrégat, et
     * inventer une date sur un document signé serait pire que de n'en afficher aucune.
     * Les PDF d'origine, eux, sont déjà archivés sur Drive et portent la bonne.
     */
    private function reprendreLesAttestationsSignees(): void
    {
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO attestation_cle (
                uuid, detenteur_id, season_id, demande_envoyee_le, token_expires_at,
                signed_at, nb_cles, remise_le, drive_path, created_at
            )
            SELECT gen_random_uuid(),
                   det.id,
                   d.season_id,
                   NULL,
                   NULL,
                   d.attestation_cle_signed_at,
                   (
                       SELECT COALESCE(SUM(CASE WHEN m.type = 'remise' THEN m.quantite ELSE -m.quantite END), 0)
                       FROM cle_mouvement m
                       WHERE m.dirigeant_id = d.uuid
                         AND m.date_mouvement <= COALESCE(d.attestation_cle_signed_at::date, CURRENT_DATE)
                   ),
                   NULL,
                   d.attestation_cle_signe_path,
                   COALESCE(d.attestation_cle_signed_at, NOW())
            FROM dirigeant d
            JOIN detenteur det ON %s
            WHERE d.attestation_cle_signe_path IS NOT NULL
            SQL, self::JOINTURE_DETENTEUR));
    }
}

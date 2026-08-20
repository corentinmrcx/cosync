<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute `transaction.created_at` : le moment où CoSync a appris l'encaissement.
 *
 * `date_paiement` est une date **métier** sans heure — celle du chèque, du virement. Utilisée
 * pour ordonner la chronologie d'une fiche, elle vaut minuit et fait donc remonter tout
 * paiement avant les événements horodatés du même jour : sur une fiche, le paiement
 * s'affichait avant l'envoi du lien et avant le formulaire qui l'a pourtant déclenché.
 *
 * Table déjà remplie en production → schéma expand / backfill / contract (§13, piège n°1) :
 * la colonne naît nullable, on remplit, puis on passe en NOT NULL.
 *
 * Backfill en deux temps, sans jamais inventer d'heure :
 *
 * - un encaissement HelloAsso est déclenché par le licencié depuis le formulaire, et le
 *   dossier garde l'instant où le tunnel de paiement s'est ouvert
 *   (`helloasso_checkout_started_at`). L'encaissement lui est nécessairement postérieur :
 *   c'est une borne **enregistrée**, on la reprend telle quelle ;
 * - pour tout le reste — virement, chèque, espèces saisis à la main — aucune borne
 *   n'existe. La ligne retombe sur `date_paiement` en **fin** de journée. Ce n'est pas
 *   une heure inventée mais l'énoncé vrai le plus faible : « connu au plus tard le soir
 *   du 16 ». Minuit affirmait l'inverse — « connu dès le début du 16 » — ce que
 *   contredisait le formulaire soumis à 17:38 le même jour, d'où un paiement affiché
 *   avant l'inscription qui l'avait déclenché.
 *
 * Les paiements saisis à partir de maintenant sont, eux, horodatés à la seconde.
 *
 * Cette migration ne touche QUE `transaction`. L'auto-génération proposait en prime de
 * supprimer `cle_mouvement.dirigeant_id` / `season_id`, les trois colonnes
 * `dirigeant.attestation_cle_*` et `season.iban` / `bic` / `titulaire_compte` : des colonnes
 * dé-mappées volontairement conservées. Elles sont écartées ici — un `DROP` se décide pour
 * lui-même, jamais en effet de bord d'un ajout de colonne.
 */
final class Version20260819123958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute transaction.created_at (horodatage de saisie) pour ordonner la chronologie des fiches';
    }

    public function up(Schema $schema): void
    {
        // 1. Expand — nullable : un ADD COLUMN NOT NULL sans DEFAULT échouerait sur une table remplie.
        $this->addSql('ALTER TABLE transaction ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // 2a. Backfill des encaissements en ligne : l'ouverture du tunnel HelloAsso est un
        //     instant enregistré, et le paiement lui est forcément postérieur.
        $this->addSql(<<<'SQL'
            UPDATE transaction t
               SET created_at = d.helloasso_checkout_started_at
              FROM dossier_club d
             WHERE d.licencie_id = t.licencie_id
               AND t.external_payment_id IS NOT NULL
               AND d.helloasso_checkout_started_at IS NOT NULL
               -- Le dossier ne retient que le DERNIER tunnel ouvert : on ne s'en sert que
               -- s'il tombe le jour même du paiement, sinon la borne ne le concerne pas.
               AND d.helloasso_checkout_started_at::date = t.date_paiement
               AND t.created_at IS NULL
            SQL);

        // 2b. Tout le reste — saisi à la main, heure jamais enregistrée : fin de journée,
        //     seule borne vraie (« connu au plus tard ce soir-là »).
        $this->addSql("UPDATE transaction SET created_at = date_paiement::timestamp + INTERVAL '23:59:59' WHERE created_at IS NULL");

        // 3. Contract — plus aucune ligne vide, la colonne devient obligatoire.
        $this->addSql('ALTER TABLE transaction ALTER COLUMN created_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Perte de données assumée et limitée : l'horodatage de saisie disparaît, les
        // encaissements eux-mêmes (montant, mode, date de paiement) sont intacts.
        $this->addSql('ALTER TABLE transaction DROP created_at');
    }
}

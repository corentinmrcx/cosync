<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement en ligne des cotisations via HelloAsso Checkout.
 *
 * 1. dossier_club.helloasso_checkout_intent_id : mémorise la dernière intention de paiement
 *    créée pour ce dossier, afin de pouvoir revérifier son état auprès de l'API HelloAsso
 *    (page de retour, webhook, commande de réconciliation).
 *
 * 2. transaction.confirmed_by_id devient nullable : un paiement encaissé en ligne n'est saisi
 *    par aucun dirigeant. DROP NOT NULL ne touche aucune donnée existante.
 *
 * 3. transaction.external_payment_id + index unique : la page de retour et le webhook peuvent
 *    traiter le même paiement en parallèle, la base garantit qu'un encaissement n'est jamais
 *    enregistré deux fois. Colonne dédiée plutôt que contrainte sur "reference" : PostgreSQL
 *    autorise les NULL multiples, donc les paiements saisis à la main (numéros de chèque
 *    identiques d'un licencié à l'autre) ne sont pas contraints.
 *
 * ADD COLUMN nullable sans NOT NULL : PostgreSQL l'ajoute en métadonnée seule, aucun backfill.
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HelloAsso : intention de paiement sur le dossier club, confirmed_by nullable et unicité des paiements encaissés en ligne.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_club ADD helloasso_checkout_intent_id VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD external_payment_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ALTER confirmed_by_id DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_transaction_external_payment ON transaction (external_payment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_transaction_external_payment');
        $this->addSql('ALTER TABLE transaction DROP external_payment_id');
        $this->addSql('ALTER TABLE dossier_club DROP helloasso_checkout_intent_id');

        // Volontairement pas de "ALTER confirmed_by_id SET NOT NULL" : la contrainte échouerait
        // s'il existe des paiements en ligne, et les supprimer perdrait des encaissements réels.
    }
}

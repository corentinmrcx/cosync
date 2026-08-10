<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la précision libre associée au mode de paiement « Autre » du formulaire public.
 *
 * Le club n'accepte plus les chèques ANCV ni les chèques CAF, mais encaisse d'autres titres
 * (tickets MSA, coupons sport…). Plutôt que d'ajouter une case par titre, le formulaire
 * propose « Autre » + un champ de saisie : sans lui, le club recevrait une intention de
 * paiement sans savoir de quoi il s'agit.
 *
 * NON DESTRUCTIF — colonne nullable, aucune donnée existante touchée. Les modes `caf` et
 * `ancv` restent lisibles : ils ne sont plus proposés, mais les paiements déjà enregistrés
 * les portent toujours et sont conservés tels quels.
 */
final class Version20260810110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute dossier_club.payment_autre_precision (mode de paiement « Autre »)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_club ADD payment_autre_precision VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Destructif : les précisions saisies par les licenciés sont perdues.
        $this->addSql('ALTER TABLE dossier_club DROP payment_autre_precision');
    }
}

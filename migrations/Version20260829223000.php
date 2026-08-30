<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contacts imprimés au pied du flyer des matchs à domicile.
 *
 * NON DESTRUCTIF : une colonne nullable ajoutée à `club_settings`.
 *
 * Texte libre plutôt qu'un lien vers `Dirigeant` : ce ne sont pas des rôles du club mais
 * les personnes qui acceptent que leur numéro parte dans toutes les boîtes aux lettres du
 * village. Aucun rôle ne dit ça, et le déduire ferait publier un numéro personnel sans
 * que personne l'ait décidé.
 */
final class Version20260829223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les contacts imprimés sur le flyer des matchs à domicile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_settings ADD planning_contacts TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // DESTRUCTIF au sens strict : les contacts saisis sont perdus. Sans conséquence
        // fonctionnelle — le flyer se réimprime alors sans son bloc contacts.
        $this->addSql('ALTER TABLE club_settings DROP planning_contacts');
    }
}

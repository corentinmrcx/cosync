<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Verrou de saisie manuelle sur le texte de flocage d'un besoin de dotation.
 *
 * Le texte venait jusqu'ici du seul dossier du licencié. Quand celui-ci n'a pas pu répondre au
 * formulaire — kit créé après la validation de sa licence, incident de saisie — l'admin n'avait
 * aucun moyen de renseigner le flocage depuis l'interface, et le texte écrit à la main sur le
 * besoin repartait au premier « Recalculer les besoins ».
 *
 * NON DESTRUCTIF — un booléen avec DEFAULT false : tous les besoins existants restent en mode
 * automatique, le recalcul se comporte donc exactement comme avant sur chacun d'eux.
 */
final class Version20260818090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le verrou « flocage saisi à la main » sur les besoins de dotation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_besoin ADD personnalisation_manuelle BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Perte de données : on oublie quels flocages avaient été saisis à la main. Les textes
        // restent en base, mais le prochain recalcul les remplacera par ceux des dossiers.
        $this->addSql('ALTER TABLE dotation_besoin DROP personnalisation_manuelle');
    }
}

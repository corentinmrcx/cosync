<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personnalisation des dotations (texte de flocage).
 *
 * 1. dotation_modele_ligne.personnalisation_* : déclare qu'une option exige un texte saisi
 *    par le licencié, avec le libellé de la question et la longueur maximale. Le réglage vit
 *    sur la ligne du kit et non sur l'article : le même t-shirt peut être floqué dans un kit
 *    et pas dans un autre. NOT NULL assorti d'un DEFAULT → aucune réécriture de table.
 *
 * 2. dossier_club.dotation_personnalisation : la réponse du licencié, { groupeChoix: texte },
 *    jumelle de dotation_choix déjà en place.
 *
 * 3. dotation_besoin.personnalisation : le texte figé au moment de la résolution, c'est-à-dire
 *    ce qui part réellement en flocage. 60 caractères pour laisser de la marge au-delà de la
 *    longueur par défaut.
 *
 * Toutes les colonnes de données sont nullables : aucun dossier ni besoin existant n'a de
 * flocage, il n'y a rien à backfiller.
 */
final class Version20260806110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personnalisation des dotations : réglages sur la ligne de kit, réponse du licencié, texte figé sur le besoin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_modele_ligne ADD personnalisation_requise BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE dotation_modele_ligne ADD personnalisation_label VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE dotation_modele_ligne ADD personnalisation_max_length INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_club ADD dotation_personnalisation JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE dotation_besoin ADD personnalisation VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // DESTRUCTIF : perd les textes saisis par les licenciés et ceux figés sur les besoins
        // déjà remis. À n'exécuter qu'en rollback immédiat, avant toute saisie réelle.
        $this->addSql('ALTER TABLE dotation_besoin DROP personnalisation');
        $this->addSql('ALTER TABLE dossier_club DROP dotation_personnalisation');
        $this->addSql('ALTER TABLE dotation_modele_ligne DROP personnalisation_max_length');
        $this->addSql('ALTER TABLE dotation_modele_ligne DROP personnalisation_label');
        $this->addSql('ALTER TABLE dotation_modele_ligne DROP personnalisation_requise');
    }
}

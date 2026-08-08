<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cible « rôle dirigeant » sur les affectations de dotation : un responsable foot, un responsable
 * d'équipe et un dirigeant standard n'ont pas le même kit.
 *
 * Le rôle est un enum applicatif (App\Enum\DirigeantRole), pas une table : la colonne stocke sa
 * valeur. Nullable et sans NOT NULL — aucun backfill, les affectations existantes gardent leur
 * cible telle quelle.
 */
final class Version20260807203718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la cible rôle dirigeant sur dotation_affectation.';
    }

    public function up(Schema $schema): void
    {
        // Pas d'index : les affectations d'une saison sont chargées en bloc puis filtrées en PHP
        // par DotationResolver, jamais interrogées par rôle. Un index non déclaré sur l'entité
        // reviendrait en plus polluer chaque `make:migration` suivant d'un DROP INDEX parasite.
        $this->addSql('ALTER TABLE dotation_affectation ADD role VARCHAR(32) DEFAULT NULL');
    }

    /** DESTRUCTIF : supprime les affectations ciblant un rôle dirigeant. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dotation_affectation DROP role');
    }
}

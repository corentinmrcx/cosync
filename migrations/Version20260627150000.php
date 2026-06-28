<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'num_licence unique par saison (et non globalement) pour licencie et dirigeant';
    }

    public function up(Schema $schema): void
    {
        // licencie : remplace unique(num_licence) → unique(num_licence, season_id)
        $this->addSql('DROP INDEX IF EXISTS uniq_3b755612d8a9fca1');
        $this->addSql('CREATE UNIQUE INDEX uniq_licencie_num_licence_season ON licencie (num_licence, season_id)');

        // dirigeant : même correction
        $this->addSql('DROP INDEX IF EXISTS uniq_bec71e71d8a9fca1');
        $this->addSql('CREATE UNIQUE INDEX uniq_dirigeant_num_licence_season ON dirigeant (num_licence, season_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_licencie_num_licence_season');
        $this->addSql('CREATE UNIQUE INDEX uniq_3b755612d8a9fca1 ON licencie (num_licence)');

        $this->addSql('DROP INDEX IF EXISTS uniq_dirigeant_num_licence_season');
        $this->addSql('CREATE UNIQUE INDEX uniq_bec71e71d8a9fca1 ON dirigeant (num_licence)');
    }
}

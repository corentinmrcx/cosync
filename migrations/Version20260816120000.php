<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écoulement d'un stock d'ancien fournisseur : un article de stock peut déclarer en remplacer
 * un autre tant qu'il en reste, et un besoin de dotation retenir l'article réellement servi.
 *
 * NON DESTRUCTIF — trois colonnes ajoutées, toutes nullables ou avec DEFAULT :
 *
 * - `stock_item.remplace_article_id` : NULL partout au départ, donc aucun écoulement en cours
 *   et aucun arbitrage déclenché. La règle se déclare article par article depuis l'admin ;
 * - `dotation_besoin.article_ecoulement_id` : NULL = servi par l'article du kit, ce qui est
 *   exactement le comportement d'avant pour toutes les lignes existantes ;
 * - `dotation_besoin.article_manuel` : DEFAULT false, aucune ligne n'est épinglée.
 *
 * Les deux clés étrangères sont en ON DELETE SET NULL, comme celles de `grille_taille_id` et
 * `mouvement_sortie_id` : supprimer un article de catalogue ne doit pas emporter les besoins
 * ni les articles qui le référencent. `StockItemService::analyserSuppression()` traite de
 * toute façon ces liens comme des emplois et fait archiver plutôt que supprimer.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'écoulement de stock : article remplacé et article servi en dotation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD remplace_article_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT FK_6017DDA4F768C2 FOREIGN KEY (remplace_article_id) REFERENCES stock_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6017DDA4F768C2 ON stock_item (remplace_article_id)');

        $this->addSql('ALTER TABLE dotation_besoin ADD article_ecoulement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dotation_besoin ADD article_manuel BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE dotation_besoin ADD CONSTRAINT FK_CC970C16DCA8DFD6 FOREIGN KEY (article_ecoulement_id) REFERENCES stock_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_CC970C16DCA8DFD6 ON dotation_besoin (article_ecoulement_id)');
    }

    public function down(Schema $schema): void
    {
        // Perte de données : les règles d'écoulement déclarées et les articles épinglés à la
        // main sur des lignes de dotation disparaissent. Les besoins concernés reviendraient à
        // l'article de leur kit — donc au rachat de neuf par-dessus l'ancien stock.
        $this->addSql('ALTER TABLE dotation_besoin DROP CONSTRAINT FK_CC970C16DCA8DFD6');
        $this->addSql('DROP INDEX IDX_CC970C16DCA8DFD6');
        $this->addSql('ALTER TABLE dotation_besoin DROP article_ecoulement_id');
        $this->addSql('ALTER TABLE dotation_besoin DROP article_manuel');

        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT FK_6017DDA4F768C2');
        $this->addSql('DROP INDEX IDX_6017DDA4F768C2');
        $this->addSql('ALTER TABLE stock_item DROP remplace_article_id');
    }
}

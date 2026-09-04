<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `club.configurer` se scinde en quatre droits.
 *
 * NON DESTRUCTIF : aucune colonne, aucune table. Seul le **contenu** de
 * `role_acces.permissions` change, et il ne peut que s'enrichir — un rôle qui pouvait tout
 * régler dans « Le club » peut toujours tout y régler après.
 *
 * Le cran unique obligeait à donner les tailles et les catégories FFF à qui n'avait besoin
 * que du RIB. Chaque écran porte désormais le sien :
 *
 * | Écran                        | Droit               |
 * |------------------------------|---------------------|
 * | Identité + signataire        | `club.identite`     |
 * | Coordonnées bancaires        | `club.rib`          |
 * | Relances automatiques        | `club.relances`     |
 * | Catégories FFF, tailles      | `club.referentiels` |
 *
 * ⚠️ **Sans cette migration, les rôles perdraient l'accès en silence.** La valeur d'une
 * permission est stockée en clair dans le `json` ; `club.configurer` n'existant plus au
 * catalogue, `Permission::depuisValeurs()` l'écarterait sans rien dire, et la présidente
 * découvrirait au premier clic que « Le club » a disparu de sa navbar.
 *
 * Le nouveau tableau est **calculé en PHP** — la colonne est un `json` (pas `jsonb`), Postgres
 * n'y offre ni test d'appartenance ni retrait d'élément — mais il est **écrit par `addSql()`**
 * et non par la connexion : c'est ce qui le fait apparaître dans `make prod-migrate-dry`. Une
 * migration de données qu'on ne peut pas relire avant de l'appliquer n'a pas sa place ici (§13).
 *
 * `down()` refait le chemin inverse : les quatre droits redeviennent `club.configurer` dès
 * que l'un d'eux est présent. Ce n'est pas une identité parfaite — un rôle qui n'aurait reçu
 * que `club.rib` remonterait avec le cran complet —, mais un retour arrière rend l'ancien
 * catalogue, où le cran fin n'existe pas.
 */
final class Version20260830120000 extends AbstractMigration
{
    private const ANCIENNE = 'club.configurer';

    /** @var list<string> */
    private const NOUVELLES = ['club.identite', 'club.rib', 'club.relances', 'club.referentiels'];

    public function getDescription(): string
    {
        return 'Scinde club.configurer en club.identite, club.rib, club.relances et club.referentiels';
    }

    public function up(Schema $schema): void
    {
        $this->remplacer([self::ANCIENNE], self::NOUVELLES);
    }

    public function down(Schema $schema): void
    {
        $this->remplacer(self::NOUVELLES, [self::ANCIENNE]);
    }

    /**
     * Tout rôle portant l'une des permissions `$retirees` les perd et reçoit `$ajoutees`.
     *
     * L'ordre du catalogue n'est pas préservé — le tableau n'est qu'un ensemble, l'écran
     * d'un rôle le regroupe par domaine à l'affichage.
     *
     * @param list<string> $retirees
     * @param list<string> $ajoutees
     */
    private function remplacer(array $retirees, array $ajoutees): void
    {
        // `--dry-run` joue aussi cette méthode : sans la table, il n'y a rien à convertir.
        if (!$this->connection->createSchemaManager()->tablesExist(['role_acces'])) {
            return;
        }

        $roles = $this->connection->fetchAllAssociative('SELECT id, permissions FROM role_acces');
        $convertis = 0;

        foreach ($roles as $role) {
            /** @var list<string> $permissions */
            $permissions = json_decode((string) $role['permissions'], true) ?: [];

            if (array_intersect($permissions, $retirees) === []) {
                continue;
            }

            $restantes = array_values(array_diff($permissions, $retirees));
            $nouvelles = array_values(array_unique([...$restantes, ...$ajoutees]));

            $this->addSql('UPDATE role_acces SET permissions = ? WHERE id = ?', [
                json_encode($nouvelles, JSON_THROW_ON_ERROR),
                $role['id'],
            ]);

            ++$convertis;
        }

        $this->write(sprintf('  %d rôle(s) à convertir.', $convertis));
    }
}

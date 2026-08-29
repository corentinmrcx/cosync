<?php declare(strict_types=1);

namespace App\Tests\Service\Compte;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;
use App\Service\Compte\PermissionCollector;
use PHPUnit\Framework\TestCase;

/**
 * La seule hiérarchie du dispositif : une écriture entraîne sa lecture, de proche en proche.
 *
 * Ce que ces tests verrouillent, c'est l'invariant qui rend les rôles sûrs : **on ne peut pas
 * composer un rôle capable de modifier ce qu'il n'a pas le droit de consulter**. Le jour où
 * le dépliage s'arrêterait au premier niveau, un rôle pourrait réceptionner une commande sans
 * pouvoir ouvrir l'article qu'elle porte — et ça ne se verrait qu'au premier clic.
 */
final class PermissionCollectorTest extends TestCase
{
    private PermissionCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new PermissionCollector();
    }

    public function testUneEcritureEntraineSaLecture(): void
    {
        $accordees = $this->collector->completer([Permission::STOCK_GERER]);

        self::assertContains(Permission::STOCK_LIRE, $accordees);
    }

    /**
     * `commande.gerer` → `commande.lire` → `stock.lire` : trois niveaux, dont le dernier
     * n'est jamais mentionné par la permission de départ.
     */
    public function testLesImplicationsSeDeplientEnChaine(): void
    {
        $accordees = $this->collector->completer([Permission::COMMANDE_GERER]);

        self::assertContains(Permission::COMMANDE_LIRE, $accordees);
        self::assertContains(Permission::STOCK_LIRE, $accordees, 'Le dépliage doit être transitif.');
    }

    /** Les paiements se saisissent depuis la fiche d'un licencié : sans elle, le geste est injouable. */
    public function testEncaisserOuvreLaFicheDuLicencie(): void
    {
        $accordees = $this->collector->completer([Permission::PAIEMENT_ENCAISSER]);

        self::assertContains(Permission::PAIEMENT_LIRE, $accordees);
        self::assertContains(Permission::EFFECTIF_LIRE, $accordees);
    }

    /** L'ordre rendu est celui du catalogue, pas celui de la saisie : c'est lui que les écrans affichent. */
    public function testLOrdreRenduEstCeluiDuCatalogue(): void
    {
        $accordees = $this->collector->completer([Permission::STOCK_GERER, Permission::EFFECTIF_LIRE]);

        self::assertSame(
            [Permission::EFFECTIF_LIRE, Permission::STOCK_LIRE, Permission::STOCK_GERER],
            $accordees,
        );
    }

    public function testLesRolesDUnCompteSAdditionnent(): void
    {
        $tresorerie = (new RoleAcces())->setNom('Trésorerie')->setPermissions([Permission::PAIEMENT_ENCAISSER]);
        $intendance = (new RoleAcces())->setNom('Intendance')->setPermissions([Permission::STOCK_GERER]);

        $user = (new User())->setEmail('cumul@example.test');
        $user->ajouterRoleAcces($tresorerie)->ajouterRoleAcces($intendance);

        self::assertTrue($this->collector->accorde($user, Permission::PAIEMENT_ENCAISSER));
        self::assertTrue($this->collector->accorde($user, Permission::STOCK_GERER));
        self::assertFalse($this->collector->accorde($user, Permission::DIAGNOSTIC_ACCEDER));
    }

    public function testUnCompteSansRoleNAccordeRien(): void
    {
        $user = (new User())->setEmail('vide@example.test');

        self::assertSame([], $this->collector->pour($user));
        self::assertFalse($this->collector->accorde($user, Permission::EFFECTIF_LIRE));
    }

    /** Le passe-partout : sans lui, une case décochée par erreur ferme l'application pour de bon. */
    public function testLeSuperAdminObtientToutSansPorterAucunRole(): void
    {
        $user = (new User())->setEmail('super@example.test')->setSuperAdmin(true);

        foreach (Permission::cases() as $permission) {
            self::assertTrue($this->collector->accorde($user, $permission), $permission->value);
        }
    }

    /**
     * Il obtient tout, mais ne *porte* rien : afficher « toutes les permissions » sur sa fiche
     * laisserait croire qu'on peut lui en retirer une.
     */
    public function testLeSuperAdminNePortePourtantAucunePermission(): void
    {
        $user = (new User())->setEmail('super@example.test')->setSuperAdmin(true);

        self::assertSame([], $this->collector->pour($user));
    }

    /**
     * Une permission retirée du code ne doit pas rendre inutilisables les rôles qui la
     * portaient : sinon un déploiement bloque tout le monde le temps de nettoyer la base.
     */
    public function testUneValeurInconnueDuCatalogueEstIgnoree(): void
    {
        $role = new RoleAcces();
        $role->setPermissions([Permission::STOCK_LIRE]);

        $reflet = new \ReflectionProperty(RoleAcces::class, 'permissions');
        $reflet->setValue($role, ['stock.lire', 'permission.disparue']);

        self::assertSame([Permission::STOCK_LIRE], $this->collector->deplier($role));
    }
}

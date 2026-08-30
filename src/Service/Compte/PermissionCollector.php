<?php declare(strict_types=1);

namespace App\Service\Compte;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;

/**
 * Les permissions effectives d'un compte : l'union de ses rôles, implications dépliées.
 *
 * C'est le **seul** endroit où ce calcul est fait. Le voter s'en sert pour trancher, les
 * écrans pour afficher ; les refaire chacun de leur côté les ferait diverger, et c'est le
 * côté qui aurait dérivé qui laisserait passer quelque chose.
 */
final class PermissionCollector
{
    /**
     * Un super-admin n'est pas traité ici : il passe partout, et c'est le voter qui court-circuite.
     * Rendre « toutes les permissions » pour lui serait un mensonge utile qui finirait par
     * s'afficher sur son écran de compte comme si elles lui avaient été attribuées.
     *
     * @return list<Permission>
     */
    public function pour(User $user): array
    {
        $accordees = [];

        foreach ($user->getRolesAcces() as $role) {
            foreach ($this->deplier($role) as $permission) {
                $accordees[$permission->value] = $permission;
            }
        }

        return array_values($accordees);
    }

    public function accorde(User $user, Permission $permission): bool
    {
        if ($user->estSuperAdmin()) {
            return true;
        }

        foreach ($user->getRolesAcces() as $role) {
            if (in_array($permission, $this->deplier($role), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les permissions d'un rôle, plus tout ce qu'elles impliquent, de proche en proche.
     *
     * Le dépliage est **transitif** : `commande.gerer` implique `commande.lire`, qui implique
     * `stock.lire`. S'arrêter au premier niveau donnerait un rôle capable de réceptionner une
     * commande sans pouvoir ouvrir l'article qu'elle porte.
     *
     * @return list<Permission>
     */
    public function deplier(RoleAcces $role): array
    {
        $resolues = [];
        $aTraiter = $role->getPermissions();

        while ($aTraiter !== []) {
            $permission = array_pop($aTraiter);

            if (isset($resolues[$permission->value])) {
                continue;
            }

            $resolues[$permission->value] = $permission;

            foreach ($permission->implique() as $implicite) {
                $aTraiter[] = $implicite;
            }
        }

        return array_values($resolues);
    }

    /**
     * Complète une sélection de permissions par tout ce qu'elle implique.
     *
     * Utilisé à l'enregistrement d'un rôle : cocher une écriture sans sa lecture ne doit pas
     * être seulement déconseillé, ça doit être impossible à produire. Sinon un rôle peut
     * encaisser un paiement sur une fiche qu'il n'a pas le droit d'ouvrir.
     *
     * @param list<Permission> $permissions
     *
     * @return list<Permission>
     */
    public function completer(array $permissions): array
    {
        $resolues = [];
        $aTraiter = $permissions;

        while ($aTraiter !== []) {
            $permission = array_pop($aTraiter);

            if (isset($resolues[$permission->value])) {
                continue;
            }

            $resolues[$permission->value] = $permission;

            foreach ($permission->implique() as $implicite) {
                $aTraiter[] = $implicite;
            }
        }

        // L'ordre du catalogue, pas celui de la saisie : c'est lui que les écrans affichent.
        return array_values(array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => isset($resolues[$p->value]),
        ));
    }
}

<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\User;

/**
 * Un compte administrateur dans la liste, avec ce que l'écran a le droit d'en proposer.
 *
 * Les règles de protection sont décidées par UserService : la vue ne redécide pas qui
 * peut être supprimé.
 */
final class LigneUtilisateur
{
    public function __construct(
        public readonly User $user,
        public readonly bool $estSuperAdmin,
        public readonly bool $modifiable,
        public readonly bool $supprimable,
        /** Faux sur le dernier super-admin : le retirer fermerait l'accès à l'application. */
        public readonly bool $superAdminRetirable = false,
    ) {}
}

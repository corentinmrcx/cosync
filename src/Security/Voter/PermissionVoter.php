<?php declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\Permission;
use App\Service\Compte\PermissionCollector;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Le point d'application unique des droits.
 *
 * Tout ce qui décide « cette personne a-t-elle le droit de… » passe par ici :
 * `#[IsGranted(Permission::X->value)]` sur les contrôleurs, `is_granted('x')` dans Twig.
 * Un second endroit qui trancherait de son côté finirait par ne plus dire la même chose.
 *
 * Le voter ne reconnaît que les valeurs du catalogue : les attributs `ROLE_*` de Symfony
 * lui passent sous le nez et restent traités par le mécanisme d'origine, qui garde la porte
 * d'entrée (`^/ → ROLE_USER`).
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionCollector $collector,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $permission = Permission::tryFrom($attribute);

        if (!$user instanceof User || $permission === null) {
            return false;
        }

        return $this->collector->accorde($user, $permission);
    }
}

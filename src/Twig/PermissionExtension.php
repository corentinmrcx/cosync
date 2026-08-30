<?php declare(strict_types=1);

namespace App\Twig;

use App\Service\Compte\RoutePermissionResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `peut_acceder('admin_stock_items_new')` — le compte pourra-t-il jouer cette action ?
 *
 * À préférer à `is_granted('stock.configurer')` pour tout bouton ou lien qui mène à une
 * route : le droit est alors lu sur la route elle-même plutôt que recopié dans le template.
 * Recopié, il se trompe (on garde un bouton « Modifier » derrière `stock.gerer` quand la
 * route exige `stock.configurer`) et surtout il ne suit pas — changer la permission d'une
 * action laisserait derrière elle un bouton gardé par l'ancienne.
 *
 * `is_granted()` reste le bon outil pour ce qui n'est pas un lien : une colonne de tableau,
 * un bloc d'information, une case à cocher de suppression.
 *
 * ⚠️ Masquer un bouton n'est pas le protéger. C'est `#[IsGranted]` sur le contrôleur qui
 * refuse, ici on ne fait qu'éviter d'annoncer une action qui répondrait 403.
 */
final class PermissionExtension extends AbstractExtension
{
    public function __construct(
        private readonly RoutePermissionResolver $routes,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('peut_acceder', $this->peutAcceder(...)),
        ];
    }

    public function peutAcceder(string $route): bool
    {
        foreach ($this->routes->pour($route) as $permission) {
            if (!$this->security->isGranted($permission->value)) {
                return false;
            }
        }

        return true;
    }
}

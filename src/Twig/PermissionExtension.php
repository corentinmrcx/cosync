<?php declare(strict_types=1);

namespace App\Twig;

use App\Enum\DomainePermission;
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
            new TwigFunction('possede_un_droit', $this->possedeUnDroit(...)),
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

    /**
     * Le compte a-t-il au moins un droit dans ce domaine ?
     *
     * Pour la porte d'entrée d'un hub, dont la route est `#[AccesLibre]` et que
     * `peut_acceder()` déclare donc ouverte à tous : elle l'est, mais elle ne mène à rien
     * quand toutes ses cartes sont fermées. Énumérer les permissions à la main dans la
     * navbar et le tableau de bord ferait oublier l'une d'elles au prochain ajout — c'est
     * le domaine qui répond, et il se met à jour tout seul.
     */
    public function possedeUnDroit(string $domaine): bool
    {
        $domaine = DomainePermission::tryFrom($domaine);

        if ($domaine === null) {
            return false;
        }

        foreach ($domaine->permissions() as $permission) {
            if ($this->security->isGranted($permission->value)) {
                return true;
            }
        }

        return false;
    }
}

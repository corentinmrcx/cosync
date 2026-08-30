<?php declare(strict_types=1);

namespace App\Service\Compte;

use App\Enum\Permission;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le droit qu'exige une route, lu là où il est déjà déclaré : sur son contrôleur.
 *
 * Un écran qui affiche un bouton doit savoir si la personne pourra jouer l'action — sinon
 * elle clique et reçoit un « Access Denied » qui ne lui apprend rien. La tentation est de
 * reporter la permission à la main dans le template (`{% if is_granted('stock.gerer') %}`) ;
 * c'est ce que faisait la poignée d'écrans déjà gardés, et ça ne passe pas l'échelle : cent
 * douze boutons, c'est cent douze endroits où se tromper de droit, et surtout où ne pas
 * suivre quand la permission d'une route change.
 *
 * Ici, **la route est la source unique**. `#[IsGranted]` la protège déjà côté serveur ; on
 * relit le même attribut pour décider de l'affichage. Les deux ne peuvent plus diverger.
 *
 * Ce que rend {@see pour()} :
 *
 * · une liste de permissions → il faut **toutes** les avoir (un contrôleur peut cumuler
 *   l'attribut de sa classe et celui de sa méthode) ;
 * · une liste vide → la route n'exige rien de particulier (`#[AccesLibre]`, route publique,
 *   ou nom de route inconnu). L'appelant affiche alors sans condition : masquer un lien dont
 *   on ne sait rien cacherait une erreur de nom de route au lieu de la montrer.
 *
 * La carte est construite une fois par déploiement et mise en cache : la parcourir à chaque
 * rendu ferait de la réflexion sur cent quatre-vingts routes pour afficher une liste.
 */
final class RoutePermissionResolver
{
    /** @var array<string, list<string>>|null */
    private ?array $carte = null;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Les permissions exigées par une route, ou une liste vide si elle n'en exige aucune.
     *
     * @return list<Permission>
     */
    public function pour(string $route): array
    {
        $valeurs = $this->carte()[$route] ?? [];
        $permissions = [];

        foreach ($valeurs as $valeur) {
            $permission = Permission::tryFrom($valeur);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /** @return array<string, list<string>> */
    private function carte(): array
    {
        return $this->carte ??= $this->cache->get(
            'route_permissions',
            fn (): array => $this->construire(),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function construire(): array
    {
        $carte = [];
        /** @var array<string, list<string>> $parClasse */
        $parClasse = [];

        foreach ($this->router->getRouteCollection() as $nom => $route) {
            $controleur = $route->getDefault('_controller');

            if (!is_string($controleur)) {
                continue;
            }

            [$classe, $methode] = array_pad(explode('::', $controleur, 2), 2, '__invoke');

            if (!class_exists($classe)) {
                continue;
            }

            $reflexion = new \ReflectionClass($classe);

            // L'attribut de classe couvre toutes les actions : lu une fois, pas par route.
            $parClasse[$classe] ??= $this->permissionsDe($reflexion);

            $exigees = $parClasse[$classe];

            if ($reflexion->hasMethod($methode)) {
                foreach ($this->permissionsDe($reflexion->getMethod($methode)) as $valeur) {
                    $exigees[] = $valeur;
                }
            }

            $carte[$nom] = array_values(array_unique($exigees));
        }

        return $carte;
    }

    /**
     * On **cumule** classe et méthode plutôt que de laisser la seconde remplacer la première :
     * c'est ce que Symfony applique réellement, les deux attributs s'exécutant l'un après
     * l'autre. `#[AccesLibre]` n'apparaît volontairement pas ici — il n'accorde rien et
     * n'annule rien ; il documente une action qu'aucun `#[IsGranted]` ne couvre, et l'absence
     * d'attribut suffit à la rendre visible.
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $cible
     *
     * @return list<string>
     */
    private function permissionsDe(\ReflectionClass|\ReflectionMethod $cible): array
    {
        $valeurs = [];

        foreach ($cible->getAttributes(IsGranted::class) as $attribut) {
            $attribut = $attribut->newInstance();

            if (is_string($attribut->attribute) && Permission::tryFrom($attribut->attribute) !== null) {
                $valeurs[] = $attribut->attribute;
            }
        }

        return $valeurs;
    }
}

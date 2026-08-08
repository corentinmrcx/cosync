<?php declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Mémorise en session les filtres et la recherche des listes admin, afin de les
 * restaurer quand l'utilisateur revient sur la liste sans paramètres (clic menu,
 * retour depuis une fiche…). Simple persistance d'état d'affichage, sans logique métier.
 */
final class ListFilterMemory
{
    private const SESSION_PREFIX = 'list_filters_';

    /**
     * Si la requête porte un état explicite (au moins un paramètre de filtre présent),
     * on le mémorise et il n'y a rien à restaurer. Sinon, on renvoie les derniers filtres
     * connus pour que le contrôleur redirige vers l'URL correspondante.
     *
     * @param string[] $params noms des paramètres à mémoriser (ex : ['team', 'status', 'search'])
     *
     * @return array<string, string>|null filtres à restaurer, ou null si rien à faire
     */
    public function restoreOrRemember(string $listKey, Request $request, array $params): ?array
    {
        $session = $request->getSession();
        $storeKey = self::SESSION_PREFIX . $listKey;

        $hasExplicitState = false;
        foreach ($params as $param) {
            if ($request->query->has($param)) {
                $hasExplicitState = true;
                break;
            }
        }

        if ($hasExplicitState) {
            $current = [];
            foreach ($params as $param) {
                $value = trim((string) $request->query->get($param, ''));
                if ($value !== '') {
                    $current[$param] = $value;
                }
            }
            $session->set($storeKey, $current);

            return null;
        }

        /** @var array<string, string> $saved */
        $saved = $session->get($storeKey, []);

        return $saved === [] ? null : $saved;
    }
}

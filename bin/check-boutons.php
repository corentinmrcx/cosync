#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Un bouton qu'on n'a pas le droit de jouer ne doit pas s'afficher.
 *
 * Le dispositif de permissions protège les **routes** (`bin/check-permissions.php` le
 * vérifie) ; il ne dit rien des **écrans**. Un rôle « consultation du stock » ouvrait donc
 * `/admin/stock/gestion` et y trouvait neuf boutons qui répondaient tous « Access Denied ».
 * L'application était sûre et illisible : la personne ne pouvait pas savoir ce qu'elle avait
 * le droit de faire autrement qu'en cliquant.
 *
 * Ce contrôle refuse un lien ou un formulaire qui mène à une route exigeant un droit que
 * l'écran lui-même n'exige pas, s'il n'est pas placé sous une garde. La garde s'écrit
 * `{% if peut_acceder('nom_de_la_route') %}` — le droit est alors lu sur la route, jamais
 * recopié dans le template : recopié, il se trompe, et surtout il ne suit pas quand la
 * permission de l'action change. `{% if is_granted('…') %}` reste accepté pour l'existant.
 *
 * Échappatoire, à écrire pour exister. En **tête de fichier**, elle vaut pour tout le
 * template — les gestes d'une fiche, par exemple, sont déjà filtrés sur
 * `FicheAction::permission()` avant d'arriver au template :
 *
 *   {# droits-verifies-cote-serveur: FicheActionsResolver filtre déjà sur la permission #}
 *
 * Posée **au fil du template**, elle ne couvre que les quelques lignes qui suivent : c'est
 * le cas d'une variable qui porte déjà le droit (`edition`, calculé par le contrôleur). Une
 * exemption de fichier entier à cet endroit-là dispenserait aussi tous les autres boutons.
 *
 * Le contrôle est **syntaxique** : il vérifie qu'une garde existe, pas qu'elle est la bonne.
 * C'est `RoutePermissionResolver` qui répond juste à l'exécution.
 *
 * À lancer : php bin/check-boutons.php
 */

$racine = dirname(__DIR__);

const MARQUEUR_EXEMPTION = 'droits-verifies-cote-serveur';

/** @var array<string, list<string>> $implications  permission → ce qu'elle entraîne */
$implications = catalogueDesImplications($racine);

/** @var array<string, list<string>> $routes  nom de route → permissions exigées */
$routes = [];
/** @var array<string, list<string>> $ecrans  template → permissions exigées pour l'ouvrir */
$ecrans = [];

foreach (glob($racine . '/src/Controller/Admin/*.php') ?: [] as $chemin) {
    [$r, $e] = analyserControleur($chemin);
    $routes += $r;

    foreach ($e as $template => $exigees) {
        // Un même template peut être rendu par plusieurs actions : on retient la moins
        // exigeante, sinon un écran atteignable en lecture serait réputé protégé par
        // l'action d'écriture qui le rend aussi.
        if (!isset($ecrans[$template]) || count($exigees) < count($ecrans[$template])) {
            $ecrans[$template] = $exigees;
        }
    }
}

$fautifs = [];
$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/templates/admin'));

foreach ($fichiers as $fichier) {
    if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), '.html.twig')) {
        continue;
    }

    $relatif = str_replace($racine . '/', '', $fichier->getPathname());
    $contenu = file_get_contents($fichier->getPathname());

    if ($contenu === false) {
        continue;
    }

    $lignes = explode("\n", $contenu);

    // En tête de fichier, l'exemption couvre tout le template ; plus bas, seulement la
    // poignée de lignes qu'elle précède.
    if (str_contains(implode("\n", array_slice($lignes, 0, 15)), MARQUEUR_EXEMPTION)) {
        continue;
    }

    // Ce que l'écran exige déjà — implications dépliées, comme `PermissionCollector` le fait
    // pour un rôle. Sans ce dépliage, l'écran d'une attestation (`paiement.attester`) serait
    // réputé incapable de rouvrir la fiche du licencié (`effectif.lire`) qu'il vient d'attester.
    $acquis = deplier($ecrans[$relatif] ?? [], $implications);

    /** @var list<bool> $gardes  pile des blocs ouverts : le bloc porte-t-il une garde ? */
    $gardes = [];

    foreach ($lignes as $numero => $ligne) {
        // Fermetures d'abord : un {% endif %} referme un bloc ouvert plus haut.
        $fermetures = preg_match_all('/\{%-?\s*end(?:if|for|block|embed)\b/', $ligne);

        foreach (liensDeLaLigne($ligne) as $route) {
            $exigees = $routes[$route] ?? [];
            $manquantes = array_values(array_diff($exigees, $acquis));

            $exempte = str_contains(implode("\n", array_slice($lignes, max(0, $numero - 5), 6)), MARQUEUR_EXEMPTION);

            if ($manquantes === [] || !$exempte && !in_array(true, $gardes, true) && !gardeSurLaLigne($ligne)) {
                if ($manquantes !== []) {
                    $fautifs[] = sprintf('%s:%d — %s exige %s', $relatif, $numero + 1, $route, implode(' + ', $manquantes));
                }
            }
        }

        for ($i = 0; $i < $fermetures; ++$i) {
            array_pop($gardes);
        }

        // `{% elseif %}` / `{% else %}` n'ouvrent ni ne ferment rien : ils rebasculent la
        // garde du bloc en cours. Sans ça, une branche gardée derrière un premier cas non
        // gardé passerait pour non gardée — et l'inverse, plus grave, pour gardée.
        if ($gardes !== [] && preg_match('/\{%-?\s*(elseif|else)\b/', $ligne, $branche)) {
            $gardes[array_key_last($gardes)] = $branche[1] === 'elseif' && gardeSurLaLigne($ligne);
        }

        // Puis les ouvertures : elles valent pour les lignes suivantes.
        if (preg_match_all('/\{%-?\s*(if|for|block|embed)\b/', $ligne, $ouvertures)) {
            foreach ($ouvertures[1] as $type) {
                $gardes[] = $type === 'if' && gardeSurLaLigne($ligne);
            }
        }
    }
}

if ($fautifs === []) {
    echo "Boutons : aucune action n'est proposée à qui ne pourra pas la jouer.\n";
    exit(0);
}

foreach ($fautifs as $f) {
    fwrite(STDERR, "Action affichée sans garde : $f\n");
}

fwrite(STDERR, sprintf(
    "\n%d action(s) mènent à une route dont l'écran n'exige pas le droit.\n" .
    "Les envelopper dans {%% if peut_acceder('nom_de_la_route') %%} … {%% endif %%},\n" .
    "ou poser {# %s: raison #} — en tête du fichier pour tout le template,\n" .
    "juste au-dessus des lignes concernées sinon — si le filtrage se fait côté serveur.\n",
    count($fautifs),
    MARQUEUR_EXEMPTION,
));
exit(1);

/**
 * Les routes déclarées par un contrôleur, et les templates que ses actions rendent.
 *
 * Lecture syntaxique, comme `bin/check-permissions.php` : le contrôle tourne dans un job CI
 * sans dépendances installées, il ne peut pas réfléchir sur des classes qu'il ne charge pas.
 *
 * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
 */
function analyserControleur(string $chemin): array
{
    $lignes = file($chemin, FILE_IGNORE_NEW_LINES);

    if ($lignes === false) {
        return [[], []];
    }

    $total = count($lignes);
    $prefixe = '';
    $permissionsClasse = [];
    $debut = 0;

    // En-tête : le préfixe de nom et le droit qui couvre toutes les actions.
    for ($i = 0; $i < $total; ++$i) {
        $ligne = trim($lignes[$i]);

        if (preg_match('/^(final |abstract )?class \w+/', $ligne)) {
            $debut = $i;
            break;
        }

        if (preg_match("/^#\\[Route\\('[^']*',\\s*name:\\s*'([a-z0-9_]*)'/", $ligne, $trouve)) {
            $prefixe = $trouve[1];
        }

        if (preg_match('/^#\[IsGranted\(Permission::(\w+)/', $ligne, $trouve)) {
            $permissionsClasse[] = $trouve[1];
        }
    }

    $routes = [];
    $ecrans = [];

    for ($i = $debut; $i < $total; ++$i) {
        if (!preg_match("/^#\\[Route\\('[^']*'(?:,\\s*name:\\s*'([a-z0-9_]*)')?/", trim($lignes[$i]), $trouve)) {
            continue;
        }

        $nom = $prefixe . ($trouve[1] ?? '');
        $exigees = $permissionsClasse;

        // Les attributs suivent le #[Route] jusqu'à la signature de la méthode.
        for ($j = $i + 1; $j < $total; ++$j) {
            $ligne = trim($lignes[$j]);

            if (preg_match('/^#\[IsGranted\(Permission::(\w+)/', $ligne, $droit)) {
                $exigees[] = $droit[1];

                continue;
            }

            if (preg_match('/^(public|private|protected) function/', $ligne)) {
                break;
            }
        }

        $routes[$nom] = array_values(array_unique($exigees));

        // Corps de l'action : les templates qu'elle rend en héritent.
        for ($k = $j; $k < $total; ++$k) {
            if ($k > $j && preg_match('/^#\[Route\(/', trim($lignes[$k]))) {
                break;
            }

            if (preg_match("#render\\('(admin/[a-z0-9_/\\-]+\\.html\\.twig)'#", $lignes[$k], $template)) {
                $ecrans['templates/' . $template[1]] = $routes[$nom];
            }
        }
    }

    return [$routes, $ecrans];
}

/**
 * Le graphe « une écriture entraîne sa lecture », lu dans `Permission::implique()`.
 *
 * Recopié ici, il divergerait au premier ajout de domaine et le contrôle se mettrait à
 * signaler des boutons parfaitement jouables — le bruit est ce qui fait ignorer un job CI.
 *
 * @return array<string, list<string>>
 */
function catalogueDesImplications(string $racine): array
{
    $source = file_get_contents($racine . '/src/Enum/Permission.php');

    if ($source === false || !preg_match('/public function implique\(\): array(.*?)\n    \}/s', $source, $bloc)) {
        return [];
    }

    $graphe = [];
    $enAttente = [];

    foreach (explode("\n", $bloc[1]) as $ligne) {
        $ligne = trim($ligne);

        if (preg_match('/^self::(\w+),$/', $ligne, $seule)) {
            $enAttente[] = $seule[1];

            continue;
        }

        if (!preg_match('/^self::(\w+)\s*=>\s*\[(.*)\],$/', $ligne, $arme)) {
            continue;
        }

        $enAttente[] = $arme[1];
        preg_match_all('/self::(\w+)/', $arme[2], $cibles);

        foreach ($enAttente as $depart) {
            $graphe[$depart] = $cibles[1];
        }

        $enAttente = [];
    }

    return $graphe;
}

/**
 * Les permissions d'un écran, plus tout ce qu'elles entraînent, de proche en proche.
 *
 * @param list<string>                  $permissions
 * @param array<string, list<string>>   $implications
 *
 * @return list<string>
 */
function deplier(array $permissions, array $implications): array
{
    $resolues = [];
    $aTraiter = $permissions;

    while ($aTraiter !== []) {
        $permission = array_pop($aTraiter);

        if (isset($resolues[$permission])) {
            continue;
        }

        $resolues[$permission] = true;

        foreach ($implications[$permission] ?? [] as $implicite) {
            $aTraiter[] = $implicite;
        }
    }

    return array_keys($resolues);
}

/** @return list<string> */
function liensDeLaLigne(string $ligne): array
{
    if (!preg_match_all("/path\\('([a-z0-9_]+)'/", $ligne, $trouves)) {
        return [];
    }

    return $trouves[1];
}

function gardeSurLaLigne(string $ligne): bool
{
    return str_contains($ligne, 'is_granted(') || str_contains($ligne, 'peut_acceder(');
}

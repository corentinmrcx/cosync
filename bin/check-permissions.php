#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Une route d'administration qui ne déclare aucun droit est ouverte à tout compte connecté.
 *
 * C'est la faille que ce contrôle ferme. Le modèle de permissions est facile ; ce qui échoue,
 * c'est **la route qu'on oublie de protéger** — et une route oubliée, ici, c'est une
 * présidente en lecture seule qui supprime une fiche signée. La discipline ne suffit pas :
 * elle tient trois mois, puis quelqu'un ajoute une action d'écriture un vendredi soir.
 *
 * Toute action publique d'un contrôleur de `src/Controller/Admin/` doit donc porter, sur sa
 * classe ou sur elle-même :
 *
 *   · `#[IsGranted(Permission::X->value)]` — le cas normal ;
 *   · `#[AccesLibre('…')]` — l'exception assumée, qui doit s'écrire pour exister.
 *
 * Le contrôle est **syntaxique**, pas sémantique : il vérifie qu'un droit est déclaré, pas
 * que c'est le bon. Le second jugement reste à la relecture.
 *
 * À lancer : php bin/check-permissions.php
 */

$racine = dirname(__DIR__);
$dossier = $racine . '/src/Controller/Admin';

/** @var list<string> $manquantes */
$manquantes = [];
/** @var list<string> $inconnues */
$inconnues = [];
/** @var list<array{0: string, 1: string}> $libres */
$libres = [];

$catalogue = catalogueDesPermissions($racine);

foreach (glob($dossier . '/*.php') ?: [] as $chemin) {
    $relatif = str_replace($racine . '/', '', $chemin);
    $lignes = file($chemin, FILE_IGNORE_NEW_LINES);

    if ($lignes === false) {
        continue;
    }

    $couvertureClasse = null;
    $attributsEnAttente = [];

    foreach ($lignes as $numero => $ligne) {
        $nettoyee = trim($ligne);

        // Les attributs s'accumulent jusqu'à la déclaration qu'ils décorent.
        if (preg_match('/^#\[(IsGranted|AccesLibre)\((.*)$/', $nettoyee, $trouve)) {
            $attributsEnAttente[] = [$trouve[1], $trouve[2], $numero + 1];

            if ($trouve[1] === 'IsGranted' && preg_match('/Permission::(\w+)->value/', $trouve[2], $nom)) {
                if (!in_array($nom[1], $catalogue, true)) {
                    $inconnues[] = sprintf('%s:%d — Permission::%s n\'existe pas', $relatif, $numero + 1, $nom[1]);
                }
            }

            continue;
        }

        // Déclaration de la classe : ce qui précède couvre toutes ses méthodes.
        if (preg_match('/^(final |abstract )?class \w+/', $nettoyee)) {
            $couvertureClasse = $attributsEnAttente === [] ? null : $attributsEnAttente[0];
            $attributsEnAttente = [];

            continue;
        }

        if (!preg_match('/^public function (\w+)\(/', $nettoyee, $methode)) {
            // Toute autre ligne significative referme le bloc d'attributs en cours.
            if ($nettoyee !== '' && !str_starts_with($nettoyee, '#[') && !str_starts_with($nettoyee, '*')
                && !str_starts_with($nettoyee, '/*') && !str_starts_with($nettoyee, '//')) {
                $attributsEnAttente = [];
            }

            continue;
        }

        $nom = $methode[1];
        $attributs = $attributsEnAttente;
        $attributsEnAttente = [];

        // Seules les actions comptent : une méthode sans #[Route] n'est pas exposée.
        if ($nom === '__construct' || !estUneAction($lignes, $numero)) {
            continue;
        }

        $couverture = $attributs[0] ?? $couvertureClasse;

        if ($couverture === null) {
            $manquantes[] = sprintf('%s:%d — %s()', $relatif, $numero + 1, $nom);

            continue;
        }

        if ($couverture[0] === 'AccesLibre') {
            $libres[] = [sprintf('%s::%s()', basename($relatif, '.php'), $nom), raison($couverture[1])];
        }
    }
}

/**
 * Une action est une méthode publique précédée d'au moins un attribut #[Route].
 *
 * On remonte les lignes plutôt que de lire les attributs déjà collectés : un commentaire de
 * documentation s'intercale souvent entre le #[Route] et la fonction.
 */
function estUneAction(array $lignes, int $numeroFonction): bool
{
    for ($i = $numeroFonction - 1; $i >= 0 && $i > $numeroFonction - 15; --$i) {
        $ligne = trim($lignes[$i]);

        if (str_starts_with($ligne, '#[Route(')) {
            return true;
        }

        // Une accolade fermante ou une autre signature : on a quitté le bloc.
        if ($ligne === '}' || str_starts_with($ligne, 'public function') || str_starts_with($ligne, 'private function')) {
            return false;
        }
    }

    return false;
}

function raison(string $arguments): string
{
    if (preg_match("/^'(.*)'\)\]$/", $arguments, $trouve)) {
        return str_replace("\\'", "'", $trouve[1]);
    }

    return $arguments;
}

/** @return list<string> les noms de cas de l'enum Permission */
function catalogueDesPermissions(string $racine): array
{
    $source = file_get_contents($racine . '/src/Enum/Permission.php');

    if ($source === false) {
        fwrite(STDERR, "src/Enum/Permission.php est illisible.\n");
        exit(1);
    }

    preg_match_all('/case (\w+) = /', $source, $trouves);

    return $trouves[1];
}

if ($manquantes === [] && $inconnues === []) {
    printf("Permissions : toutes les actions d'administration déclarent un droit (%d exceptions assumées).\n", count($libres));

    foreach ($libres as [$action, $raison]) {
        printf("  · %s — %s\n", $action, $raison);
    }

    exit(0);
}

foreach ($inconnues as $ligne) {
    fwrite(STDERR, "Permission inconnue du catalogue : $ligne\n");
}

foreach ($manquantes as $ligne) {
    fwrite(STDERR, "Action d'administration sans droit déclaré : $ligne\n");
}

fwrite(STDERR, "\nAjouter sur l'action ou sur sa classe :\n");
fwrite(STDERR, "  · #[IsGranted(Permission::XXX->value)]  — le cas normal\n");
fwrite(STDERR, "  · #[AccesLibre('pourquoi')]             — uniquement un point de navigation\n");
fwrite(STDERR, "    ou un écran qui ne parle que du compte connecté\n");
exit(1);

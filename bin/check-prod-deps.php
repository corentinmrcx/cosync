#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * L'image de production installe les dépendances avec --no-dev. Une classe fournie
 * par une dépendance de dev traverse donc toute la CI sans broncher — les tests
 * tournent avec le vendor complet — et n'explose qu'en prod, à la première
 * exécution du code qui l'utilise.
 *
 * C'est exactement ce qui est arrivé à Symfony\Component\Process\Process : tiré
 * en transitif par php-cs-fixer, jamais déclaré, absent de l'image de prod, et
 * découvert par un `app:db:backup` en échec pendant un déploiement.
 *
 * Ce contrôle compare les classes que le code de production importe au contenu
 * réel du vendor --no-dev. Il doit donc tourner APRÈS :
 *
 *     composer install --no-dev --classmap-authoritative
 */

$racine = dirname(__DIR__);
$cheminClassmap = $racine . '/vendor/composer/autoload_classmap.php';

if (!is_file($cheminClassmap)) {
    fwrite(STDERR, "Classmap introuvable. Lancer d'abord :\n");
    fwrite(STDERR, "  composer install --no-dev --classmap-authoritative\n");
    exit(1);
}

/** @var array<string, string> $classmap */
$classmap = require $cheminClassmap;
$disponibles = array_keys($classmap);

// Un vendor de dev contient tout : le contrôle passerait toujours et ne
// protégerait rien. Mieux vaut refuser de répondre que rassurer à tort.
if (isset($classmap['PHPUnit\\Framework\\TestCase'])) {
    fwrite(STDERR, "Le vendor contient les dépendances de dev : ce contrôle ne prouverait rien.\n");
    fwrite(STDERR, "Relancer avec :  composer install --no-dev --classmap-authoritative\n");
    exit(1);
}

// Seuls les répertoires réellement embarqués et exécutés en production. tests/
// est hors sujet : il ne part jamais dans l'image.
$repertoires = ['src', 'bin', 'config', 'public'];

$symboles = [];
foreach ($repertoires as $repertoire) {
    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine . '/' . $repertoire, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterateur as $fichier) {
        if (!$fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }

        $contenu = (string) file_get_contents($fichier->getPathname());

        // `use function` et `use const` importent des symboles qui ne sont pas des
        // classes ; le `use ($var)` d'une closure n'importe rien du tout.
        preg_match_all('/^use\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)\s*(?:as\s+\w+\s*)?;/m', $contenu, $trouves);

        foreach ($trouves[1] as $symbole) {
            if (str_starts_with($symbole, 'App\\')) {
                continue;
            }
            $symboles[$symbole] = $fichier->getPathname();
        }
    }
}

ksort($symboles);

$manquants = [];
foreach ($symboles as $symbole => $fichier) {
    if (isset($classmap[$symbole])) {
        continue;
    }

    // `use Doctrine\ORM\Mapping as ORM;` importe un espace de noms, pas une classe :
    // il est légitime dès qu'une classe du vendor vit dessous.
    $prefixe = $symbole . '\\';
    foreach ($disponibles as $disponible) {
        if (str_starts_with($disponible, $prefixe)) {
            continue 2;
        }
    }

    $manquants[$symbole] = str_replace($racine . '/', '', $fichier);
}

if ($manquants === []) {
    printf("Dépendances de production : OK (%d symboles vérifiés)\n", count($symboles));
    exit(0);
}

fwrite(STDERR, "Ces classes sont utilisées en production mais absentes du vendor --no-dev :\n");
foreach ($manquants as $symbole => $fichier) {
    fwrite(STDERR, "  $symbole\n    utilisée par $fichier\n");
}
fwrite(STDERR, "\nDéclarer le paquet qui les fournit dans la section \"require\" de composer.json.\n");
exit(1);

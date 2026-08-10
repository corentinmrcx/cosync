#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Un fichier CSS qui n'est pas importé par app.css n'est jamais chargé : le style
 * disparaît sans qu'aucun linter ne s'en aperçoive. Ce contrôle ferme cette porte.
 */

$racine = dirname(__DIR__);
$appCss = file_get_contents($racine . '/assets/styles/app.css');

$orphelins = [];
foreach (['components', 'pages'] as $dossier) {
    foreach (glob($racine . "/assets/styles/$dossier/*.css") as $fichier) {
        $chemin = "./$dossier/" . basename($fichier);
        if (!str_contains($appCss, "@import '$chemin';")) {
            $orphelins[] = "assets/styles/$dossier/" . basename($fichier);
        }
    }
}

$casses = [];
preg_match_all("/@import '([^']+)';/", $appCss, $imports);
foreach ($imports[1] as $import) {
    if (!is_file($racine . '/assets/styles/' . ltrim($import, './'))) {
        $casses[] = $import;
    }
}

if ($orphelins === [] && $casses === []) {
    echo "Imports CSS : OK\n";
    exit(0);
}

foreach ($orphelins as $f) {
    fwrite(STDERR, "Fichier CSS jamais importé par app.css : $f\n");
}
foreach ($casses as $f) {
    fwrite(STDERR, "@import vers un fichier inexistant : $f\n");
}
exit(1);

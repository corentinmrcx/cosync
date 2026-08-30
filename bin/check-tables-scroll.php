#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Un tableau posé sans conteneur défilant fait déborder **la page entière**.
 *
 * `.table` ne se contraint pas tout seul : ses en-têtes sont en `white-space: nowrap`, et
 * six colonnes suffisent à dépasser un écran de téléphone. Le débordement remonte alors
 * jusqu'à `.main-content`, qui sort une barre de défilement horizontale — et c'est toute
 * l'application qui glisse sous le doigt, pas seulement le tableau.
 *
 * La règle est écrite dans components/data-table.css depuis toujours (« une page ne
 * redéfinit pas un tableau : elle l'enveloppe dans .table-wrapper »), mais rien ne
 * l'appliquait : trois écrans l'avaient oubliée. Ce contrôle ferme la porte.
 *
 * À lancer : php bin/check-tables-scroll.php
 */

$racine = dirname(__DIR__);
$fautifs = [];

$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/templates'));

foreach ($fichiers as $fichier) {
    if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), '.html.twig')) {
        continue;
    }

    // Les PDF et les mails ne sont pas des pages : ni défilement, ni téléphone.
    $chemin = str_replace($racine . '/', '', $fichier->getPathname());
    if (str_starts_with($chemin, 'templates/pdf/') || str_starts_with($chemin, 'templates/email/')) {
        continue;
    }

    $lignes = file($fichier->getPathname(), FILE_IGNORE_NEW_LINES);

    foreach ($lignes as $numero => $ligne) {
        if (!preg_match('/<table[^>]*\bclass="([^"]*)"/', $ligne, $classes)) {
            continue;
        }

        // Seuls les tableaux du composant partagé sont concernés : un tableau de mise en
        // page interne (`.stock-taille-table`) vit déjà dans le conteneur de son parent.
        if (!in_array('table', preg_split('/\s+/', trim($classes[1])), true)) {
            continue;
        }

        // Le conteneur précède toujours le tableau de très près. On remonte quelques
        // lignes plutôt qu'une seule : un `x-show` ou un commentaire s'intercale souvent.
        $contexte = implode(' ', array_slice($lignes, max(0, $numero - 6), 7));

        if (!str_contains($contexte, 'table-wrapper')) {
            $fautifs[] = sprintf('%s:%d', $chemin, $numero + 1);
        }
    }
}

if ($fautifs === []) {
    echo "Tableaux : tous enveloppés dans un conteneur défilant.\n";
    exit(0);
}

foreach ($fautifs as $f) {
    fwrite(STDERR, "Tableau sans .table-wrapper (la page débordera sur mobile) : $f\n");
}
fwrite(STDERR, "\nEnvelopper le <table> dans <div class=\"table-wrapper\">, en ajoutant\n");
fwrite(STDERR, "  · table-wrapper-nu  si le tableau est déjà dans une carte (pas de second cadre)\n");
fwrite(STDERR, "  · table-cartes      pour qu'il passe en cartes empilées sur téléphone\n");
exit(1);

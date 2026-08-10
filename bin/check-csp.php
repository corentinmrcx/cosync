#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Le CSP n'existe qu'en production : docker/nginx/nginx.conf ne pose aucun en-tête de
 * sécurité. Une ressource externe ajoutée à un template traverse donc tout le
 * développement et toute la CI sans broncher, et n'est bloquée que devant le licencié.
 *
 * C'est arrivé deux fois de suite : la feuille Google Fonts (style-src fermé sur 'self',
 * interface muette en police système) puis la redirection vers HelloAsso (form-action
 * limité à www.helloasso.com alors que la chaîne passe par le domaine nu).
 *
 * Ce contrôle compare le CSP réellement posé en production aux origines externes que les
 * pages atteignent : celles écrites dans les templates et les CSS, plus celles que seul
 * l'exécution révèle, déclarées ici explicitement.
 */

$racine = dirname(__DIR__);
$cheminConf = $racine . '/docker/nginx/nginx.prod.conf';
$cheminConfDev = $racine . '/docker/nginx/nginx.conf';

/**
 * Origines qu'aucune analyse statique ne peut voir : elles n'apparaissent nulle part dans
 * les sources, mais le navigateur les atteint. Toute nouvelle intégration externe
 * (paiement, cartographie, statistiques) s'ajoute ici en même temps qu'au CSP.
 *
 * @var list<array{origine: string, directive: string, raison: string}>
 */
$origcinesExecution = [
    [
        'origine' => 'https://fonts.gstatic.com',
        'directive' => 'font-src',
        'raison' => "la feuille servie par fonts.googleapis.com charge les .woff2 depuis cette seconde origine",
    ],
    [
        'origine' => 'https://www.helloasso.com',
        'directive' => 'form-action',
        'raison' => "POST /paiement/checkout répond une 302 vers la page de paiement HelloAsso",
    ],
    [
        'origine' => 'https://helloasso.com',
        'directive' => 'form-action',
        'raison' => "HelloAsso rebondit vers son domaine nu ; form-action est vérifié à chaque saut",
    ],
];

// Les templates de mail et de PDF ne sont jamais rendus par un navigateur : DomPDF et les
// clients de messagerie ne connaissent pas le CSP. Les y inclure ne ferait que du bruit.
$repertoiresIgnores = ['/templates/email/', '/templates/pdf/'];

// ── Lecture du CSP de production ─────────────────────────────────────────────

$cspProd = lireCsp($cheminConf);

// Le développement ne sert à repérer les blocages que s'il applique exactement la même
// politique. Une divergence, même bien intentionnée, ramène l'angle mort d'origine.
$cspDev = lireCsp($cheminConfDev);

if ($cspDev !== $cspProd) {
    fwrite(STDERR, "Le CSP de développement diverge de celui de production :\n\n");
    fwrite(STDERR, "  prod : $cspProd\n\n");
    fwrite(STDERR, "  dev  : $cspDev\n\n");
    fwrite(STDERR, "Reporter la même valeur dans docker/nginx/nginx.conf et docker/nginx/nginx.prod.conf.\n");
    exit(1);
}

/** @var array<string, list<string>> $policy directive => sources */
$policy = [];
foreach (explode(';', $cspProd) as $bloc) {
    $morceaux = preg_split('/\s+/', trim($bloc), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($morceaux === []) {
        continue;
    }

    $policy[strtolower(array_shift($morceaux))] = $morceaux;
}

// ── Collecte des origines externes présentes dans les sources ────────────────

/** @var list<array{origine: string, directive: string, raison: string}> $besoins */
$besoins = [];

foreach (fichiers($racine . '/templates', 'twig') as $fichier) {
    $relatif = substr($fichier, strlen($racine));

    foreach ($repertoiresIgnores as $ignore) {
        if (str_contains($relatif, $ignore)) {
            continue 2;
        }
    }

    $contenu = (string) file_get_contents($fichier);

    // <a href> est délibérément absent : une navigation par lien n'est soumise à aucune
    // directive. rel="preconnect" non plus — le navigateur ouvre une connexion, il ne
    // charge aucune ressource.
    $balises = [
        'link' => ['attribut' => 'href', 'directive' => 'style-src', 'condition' => 'stylesheet'],
        'script' => ['attribut' => 'src', 'directive' => 'script-src', 'condition' => null],
        'img' => ['attribut' => 'src', 'directive' => 'img-src', 'condition' => null],
        'iframe' => ['attribut' => 'src', 'directive' => 'frame-src', 'condition' => null],
        'form' => ['attribut' => 'action', 'directive' => 'form-action', 'condition' => null],
    ];

    foreach ($balises as $balise => $regle) {
        $motif = sprintf('/<%s\b[^>]*\b%s\s*=\s*"(https?:\/\/[^"]+)"[^>]*>/i', $balise, $regle['attribut']);

        if (preg_match_all($motif, $contenu, $trouves, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($trouves as $trouve) {
            if ($regle['condition'] !== null && !str_contains(strtolower($trouve[0]), $regle['condition'])) {
                continue;
            }

            $besoins[] = [
                'origine' => origine($trouve[1]),
                'directive' => $regle['directive'],
                'raison' => sprintf('<%s> dans %s', $balise, ltrim($relatif, '/')),
            ];
        }
    }
}

// Les url() d'une feuille de style : une police et une image ne relèvent pas de la même
// directive, l'extension tranche. public/build n'existe qu'après `npm run build` — on le
// lit s'il est là (il embarque le CSS des bibliothèques, Quill notamment).
$css = [...fichiers($racine . '/assets/styles', 'css'), ...fichiers($racine . '/public/build', 'css')];

foreach ($css as $fichier) {
    $contenu = (string) file_get_contents($fichier);

    if (preg_match_all('/url\(\s*[\'"]?(https?:\/\/[^\'")]+)/i', $contenu, $trouves) === 0) {
        continue;
    }

    foreach ($trouves[1] as $url) {
        $estPolice = preg_match('/\.(woff2?|ttf|otf|eot)(\?|$)/i', $url) === 1;

        $besoins[] = [
            'origine' => origine($url),
            'directive' => $estPolice ? 'font-src' : 'img-src',
            'raison' => sprintf('url() dans %s', ltrim(substr($fichier, strlen($racine)), '/')),
        ];
    }
}

$besoins = [...$besoins, ...$origcinesExecution];

// ── Confrontation ────────────────────────────────────────────────────────────

$manquants = [];

foreach ($besoins as $besoin) {
    if (!estAutorisee($besoin['origine'], $besoin['directive'], $policy)) {
        $manquants[] = $besoin;
    }
}

if ($manquants !== []) {
    fwrite(STDERR, "Le CSP de production bloque des ressources que les pages chargent :\n\n");

    foreach ($manquants as $manquant) {
        fwrite(STDERR, sprintf(
            "  %s  manque à %s\n    %s\n",
            $manquant['origine'],
            $manquant['directive'],
            $manquant['raison'],
        ));
    }

    fwrite(STDERR, "\nCorriger l'en-tête Content-Security-Policy de docker/nginx/nginx.prod.conf,\n");
    fwrite(STDERR, "et reporter le même en-tête dans docker/nginx/nginx.conf pour que le\n");
    fwrite(STDERR, "développement voie désormais la même chose que la production.\n");

    exit(1);
}

printf("CSP de production : %d origine(s) externe(s) vérifiée(s), toutes autorisées.\n", count($besoins));
exit(0);

// ── Outils ───────────────────────────────────────────────────────────────────

/**
 * Vérifie une origine contre une directive, en suivant les replis prévus par la
 * spécification. form-action n'en a aucun : son absence n'interdit rien, mais elle ne
 * peut pas non plus être suppléée par default-src — c'est précisément le piège qui a
 * coûté le bouton de paiement.
 *
 * @param array<string, list<string>> $policy
 */
function estAutorisee(string $origine, string $directive, array $policy): bool
{
    $replis = match ($directive) {
        'form-action' => [],
        'frame-src' => ['child-src', 'default-src'],
        default => ['default-src'],
    };

    foreach ([$directive, ...$replis] as $candidate) {
        if (!isset($policy[$candidate])) {
            continue;
        }

        foreach ($policy[$candidate] as $source) {
            if (correspond($source, $origine)) {
                return true;
            }
        }

        return false;
    }

    // Ni la directive ni ses replis ne sont posés : rien n'est restreint.
    return true;
}

/** Applique la correspondance d'une source CSP : joker de sous-domaine, schéma seul, ou origine exacte. */
function correspond(string $source, string $origine): bool
{
    $source = trim($source, "'");

    if ($source === '*') {
        return true;
    }

    // 'self', 'unsafe-inline', 'none'… ne désignent jamais une origine externe.
    if (!str_contains($source, '.') && !str_contains($source, ':')) {
        return false;
    }

    if (preg_match('/^https?:$/i', $source) === 1) {
        return str_starts_with($origine, rtrim($source, ':') . '://');
    }

    $hoteSource = strtolower((string) preg_replace('#^https?://#i', '', $source));
    $hoteOrigine = strtolower((string) preg_replace('#^https?://#i', '', $origine));

    if (str_starts_with($hoteSource, '*.')) {
        return str_ends_with($hoteOrigine, substr($hoteSource, 1));
    }

    return $hoteSource === $hoteOrigine;
}

/** Réduit une URL à son origine (schéma + hôte + port), seule chose que le CSP compare. */
function origine(string $url): string
{
    $parties = parse_url($url);

    if (!is_array($parties) || !isset($parties['scheme'], $parties['host'])) {
        return $url;
    }

    return $parties['scheme'] . '://' . $parties['host'] . (isset($parties['port']) ? ':' . $parties['port'] : '');
}

/** Extrait la valeur brute de l'en-tête Content-Security-Policy d'une configuration Nginx. */
function lireCsp(string $chemin): string
{
    if (!is_file($chemin)) {
        erreurFatale("Configuration Nginx introuvable : $chemin");
    }

    $conf = (string) file_get_contents($chemin);

    if (preg_match('/add_header\s+Content-Security-Policy\s+"([^"]+)"/i', $conf, $m) !== 1) {
        erreurFatale("Aucun en-tête Content-Security-Policy dans $chemin.");
    }

    return trim($m[1]);
}

/** @return list<string> */
function fichiers(string $repertoire, string $extension): array
{
    if (!is_dir($repertoire)) {
        return [];
    }

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repertoire, FilesystemIterator::SKIP_DOTS));
    $trouves = [];

    foreach ($iterateur as $fichier) {
        if ($fichier instanceof SplFileInfo && strtolower($fichier->getExtension()) === $extension) {
            $trouves[] = $fichier->getPathname();
        }
    }

    sort($trouves);

    return $trouves;
}

function erreurFatale(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

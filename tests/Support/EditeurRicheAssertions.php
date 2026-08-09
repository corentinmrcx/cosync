<?php declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Les écrans qui embarquent un éditeur riche l'initialisent depuis un <script> inline.
 * Ce script est fragile de deux façons, toutes deux déjà tombées en production :
 *
 *  — le placeholder doit être un littéral de chaîne *entre guillemets* ; `|e('js')`
 *    échappe sans guillemeter, ce qui produit une SyntaxError et fait rejeter le bloc
 *    entier par le navigateur — l'éditeur ne s'affiche jamais ;
 *  — l'appel doit attendre `DOMContentLoaded` : les scripts Encore sont chargés en
 *    `defer`, donc `window.initEditeurRiche` n'existe pas encore quand le script
 *    inline s'exécute.
 *
 * Aucune des deux ne se voit à la relecture du Twig : d'où ces assertions.
 */
trait EditeurRicheAssertions
{
    private function assertEditeurRicheInitialisable(Crawler $page, string $ecran): void
    {
        $script = $this->scriptInlineDInitialisation($page, $ecran);

        self::assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]DOMContentLoaded[\'"]/',
            $script,
            sprintf(
                'L\'éditeur de %s doit attendre DOMContentLoaded : les scripts Encore sont en defer, '
                . 'initEditeurRiche n\'est pas encore défini au moment du script inline.',
                $ecran,
            ),
        );

        self::assertMatchesRegularExpression(
            '/placeholder:\s*"/',
            $script,
            sprintf(
                'Le placeholder de %s doit être une chaîne guillemetée (|json_encode|raw). '
                . 'Sans guillemets, le bloc <script> entier est une SyntaxError.',
                $ecran,
            ),
        );
    }

    private function scriptInlineDInitialisation(Crawler $page, string $ecran): string
    {
        $scripts = $page->filter('script:not([src])')->each(
            static fn (Crawler $node): string => $node->text(''),
        );

        foreach ($scripts as $script) {
            if (str_contains($script, 'initEditeurRiche')) {
                return $script;
            }
        }

        self::fail(sprintf('Aucun script d\'initialisation de l\'éditeur riche trouvé sur %s.', $ecran));
    }
}

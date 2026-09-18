<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\Flocage\FlocageArticle;
use App\DTO\Flocage\FlocageLigne;
use App\DTO\Flocage\ListeFlocage;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\TailleRepository;
use App\Service\Stock\StockTaillePresenter;

/**
 * Monte la liste de flocage telle qu'elle part chez le floqueur.
 *
 * **La source est celle de l'écran** — {@see DotationSuiviPresenter::flocages()} — et non une
 * requête à elle : le club relit la liste à l'écran avant de l'envoyer, et deux requêtes
 * finiraient par ne plus dire la même chose. Ce service ne fait que la retourner du côté du
 * floqueur : il jette le porteur et l'équipe, regroupe par article, et additionne les pièces
 * qui portent le même texte dans la même taille.
 *
 * La taille sort à l'étiquette du carton (« 37-40 ») : le floqueur est devant les cartons du
 * fournisseur, le « 37 » du stock se lirait chez lui comme une pointure (§7 ter).
 *
 * Lecture seule.
 */
final class ListeFlocageCollector
{
    public function __construct(
        private readonly DotationSuiviPresenter $suivi,
        private readonly StockTaillePresenter $etiquettes,
        private readonly TailleRepository $tailleRepository,
    ) {}

    public function collecter(Season $season): ListeFlocage
    {
        $parArticle = [];
        $pieces = 0;

        foreach ($this->suivi->flocages($season) as $besoin) {
            // Le texte est non nul : `flocages()` ne rend que les besoins qui en portent un.
            $texte = (string) $besoin->getPersonnalisation();
            $article = $besoin->getArticleServi();
            $id = $article->getId();
            $taille = $besoin->getTaille();

            $parArticle[$id] ??= ['article' => $article, 'lignes' => [], 'pieces' => 0];

            // Même article, même taille, même texte : un seul geste, répété. Le floqueur n'a
            // pas besoin de lire deux fois la même consigne.
            $cle = ($taille ?? '') . "\0" . $texte;
            $parArticle[$id]['lignes'][$cle] ??= ['taille' => $taille, 'texte' => $texte, 'quantite' => 0];
            $parArticle[$id]['lignes'][$cle]['quantite'] += $besoin->getQuantite();
            $parArticle[$id]['pieces'] += $besoin->getQuantite();
            $pieces += $besoin->getQuantite();
        }

        $rangs = $this->rangsDesTailles();
        $articles = array_map(
            fn (array $groupe): FlocageArticle => $this->enArticle($groupe, $rangs),
            array_values($parArticle),
        );

        // Les cartons dans l'ordre où le floqueur les alignera sur son établi.
        usort($articles, static fn (FlocageArticle $a, FlocageArticle $b): int => strnatcasecmp($a->designation, $b->designation));

        return new ListeFlocage($season->getLabel(), new \DateTimeImmutable(), $articles, $pieces);
    }

    /**
     * @param array{article: StockItem, lignes: array<string, array{taille: ?string, texte: string, quantite: int}>, pieces: int} $groupe
     * @param array<string, int>                                                                                                 $rangs
     */
    private function enArticle(array $groupe, array $rangs): FlocageArticle
    {
        $item = $groupe['article'];
        $lignes = array_values($groupe['lignes']);

        // Tri sur la taille déclarée, tant qu'on l'a encore sous la main : c'est elle qui porte
        // un rang au référentiel, l'étiquette du carton en regroupe plusieurs. Alphabétique,
        // « L, M, S, XL » n'aurait été l'ordre de personne. À taille égale, les textes se
        // suivent — on les coche au fur et à mesure.
        usort($lignes, static function (array $a, array $b) use ($rangs): int {
            $rangA = $rangs[mb_strtolower($a['taille'] ?? '')] ?? PHP_INT_MAX;
            $rangB = $rangs[mb_strtolower($b['taille'] ?? '')] ?? PHP_INT_MAX;

            return [$rangA, $a['texte']] <=> [$rangB, $b['texte']];
        });

        return new FlocageArticle(
            $item->getDesignation(),
            $item->getRefCatalogue(),
            array_map(
                fn (array $ligne): FlocageLigne => new FlocageLigne(
                    $ligne['taille'] !== null ? $this->etiquettes->etiquette($item, $ligne['taille']) : null,
                    $ligne['texte'],
                    $ligne['quantite'],
                ),
                $lignes,
            ),
            $groupe['pieces'],
        );
    }

    /** @return array<string, int> libellé de taille en minuscules → rang du référentiel */
    private function rangsDesTailles(): array
    {
        $rangs = [];

        foreach ($this->tailleRepository->findAllOrdered() as $rang => $taille) {
            $rangs[mb_strtolower($taille->getLibelle())] = $rang;
        }

        return $rangs;
    }
}

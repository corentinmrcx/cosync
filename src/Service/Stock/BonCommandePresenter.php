<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Stock\AchatArticle;
use App\DTO\Stock\AchatTaille;
use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\StockItem;
use App\Service\Referentiel\TailleReferentiel;

/**
 * Met un bon de commande en forme pour le fournisseur : un article, ses tailles dessous.
 *
 * C'est la feuille qu'on a sous les yeux au téléphone ou devant le catalogue. À plat, le
 * même carton y revenait six fois, sa référence recopiée à chaque ligne, et personne ne
 * pouvait dire combien de polos la commande portait en tout.
 *
 * **Il lit les lignes enregistrées, il ne recalcule rien** : un bon dit ce qui a été
 * commandé, même si les besoins ont bougé depuis. Le tri, lui, s'applique à l'affichage —
 * les bons d'avant cet écran ont été écrits dans l'ordre des licenciés rencontrés, et ils
 * doivent se relire comme les autres.
 *
 * Lecture seule.
 */
final class BonCommandePresenter
{
    public function __construct(
        private readonly StockTaillePresenter $etiquettes,
        private readonly TailleReferentiel $tailles,
    ) {}

    /** @return list<AchatArticle> */
    public function articles(Commande $commande): array
    {
        $parArticle = [];

        foreach ($commande->getLignes() as $ligne) {
            $parArticle[$ligne->getStockItem()->getId()][] = $ligne;
        }

        $articles = array_map($this->article(...), array_values($parArticle));

        usort(
            $articles,
            static fn (AchatArticle $a, AchatArticle $b): int => strnatcasecmp(
                $a->article->getDesignation(),
                $b->article->getDesignation(),
            ),
        );

        return $articles;
    }

    /**
     * Les lignes d'un bon à plat, dans le même ordre que le document.
     *
     * L'écran de suivi les garde à plat : chaque taille y porte sa réception, et l'empiler
     * sous son article mettrait le geste au mauvais endroit. L'ordre, lui, doit être celui
     * du PDF qu'on a sous les yeux en déballant les cartons.
     *
     * @return list<CommandeLigne>
     */
    public function lignes(Commande $commande): array
    {
        $lignes = array_values($commande->getLignes()->toArray());

        usort($lignes, $this->comparer(...));

        return $lignes;
    }

    private function comparer(CommandeLigne $a, CommandeLigne $b): int
    {
        $parNom = strnatcasecmp($a->getStockItem()->getDesignation(), $b->getStockItem()->getDesignation());
        if ($parNom !== 0) {
            return $parNom;
        }

        // Deux articles peuvent porter la même désignation sans être le même carton :
        // l'identifiant départage, pour que leurs tailles ne s'entremêlent pas.
        $parArticle = $a->getStockItem()->getId() <=> $b->getStockItem()->getId();

        return $parArticle !== 0
            ? $parArticle
            : $this->tailles->comparer($a->getTaille() ?? '', $b->getTaille() ?? '');
    }

    /** @param non-empty-list<CommandeLigne> $lignes */
    private function article(array $lignes): AchatArticle
    {
        $item = $lignes[0]->getStockItem();

        // Le référentiel ordonne les tailles : « L, M, S, XL » n'aurait été l'ordre de personne.
        usort($lignes, $this->comparer(...));

        $tailles = [];
        $quantite = 0;

        foreach ($lignes as $ligne) {
            $tailles[] = new AchatTaille($this->etiquette($item, $ligne->getTaille()), $ligne->getQuantite());
            $quantite += $ligne->getQuantite();
        }

        return new AchatArticle($item, $tailles, $quantite);
    }

    /**
     * L'étiquette du carton (« 37-40 »), jamais le libellé du stock : c'est chez le
     * fournisseur qu'on lit ce document, et un « 37 » seul s'y lirait comme une pointure.
     */
    private function etiquette(StockItem $item, ?string $taille): ?string
    {
        return $taille !== null ? $this->etiquettes->etiquette($item, $taille) : null;
    }
}

<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\GrilleTailleValeur;
use App\Entity\StockItem;

/**
 * Désigne une déclinaison d'article telle qu'elle est écrite sur le carton.
 *
 * Une grille range une plage de pointures sous un seul libellé du référentiel : l'Erima
 * « 37 » habille les pointures 37 à 40. Affiché seul, ce « 37 » se lit comme une pointure —
 * un joueur qui chausse du 39 passait pour un 37, et l'admin le croyait servi par le carton
 * Nike 34-38, qui ne le couvre pas. Le libellé reste la clé du stock ; seul l'affichage
 * déplie la plage.
 */
final class StockTaillePresenter
{
    public function etiquette(StockItem $item, string $taille): string
    {
        $valeur = $this->valeurDe($item, $taille);
        $pointures = $valeur !== null ? $this->pointuresCouvertes($valeur) : [];

        // Une seule pointure couverte n'a pas de plage à déplier : le libellé suffit.
        if (count($pointures) < 2) {
            return $taille;
        }

        $premiere = $pointures[0];
        $derniere = $pointures[count($pointures) - 1];

        // Une plage trouée ne s'écrit pas « 33-36 » : elle promettrait une pointure qu'elle
        // ne couvre pas.
        return $derniere - $premiere + 1 === count($pointures)
            ? $premiere . '-' . $derniere
            : implode(', ', $pointures);
    }

    private function valeurDe(StockItem $item, string $taille): ?GrilleTailleValeur
    {
        foreach ($item->getGrilleTaille()?->getValeurs() ?? [] as $valeur) {
            if ($valeur->getCible()->getLibelle() === $taille) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * Pointures couvertes, dans l'ordre. Une valeur qui couvre des tailles de vêtement
     * (« 128 » pour « 8 ans ») n'a pas de plage : son libellé est déjà celui du carton.
     *
     * @return list<int>
     */
    private function pointuresCouvertes(GrilleTailleValeur $valeur): array
    {
        $pointures = [];

        foreach ($valeur->libellesCouverts() as $libelle) {
            if (!ctype_digit($libelle)) {
                return [];
            }

            $pointures[] = (int) $libelle;
        }

        sort($pointures);

        return $pointures;
    }
}

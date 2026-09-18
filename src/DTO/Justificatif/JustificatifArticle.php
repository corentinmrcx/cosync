<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

use App\Entity\StockItem;

/**
 * Un article de la dotation, tel que la présidente le lit : **il en faut N, on en a S, on en
 * commande N - S.**
 *
 * Trois nombres, parce que c'est le raisonnement entier de celle qui signe le devis. Tout ce
 * qui a servi à les obtenir — l'ancienne référence qu'on écoule, le sac déjà préparé, la
 * commande en cours — est du travail d'inventaire : ça se dit en note quand ça existe, ça
 * n'occupe pas une colonne toute l'année.
 *
 * Les articles dont il n'y a **rien** à commander restent de la partie. C'est même le
 * meilleur argument du document : « il en faut 8, on en a 8, on n'achète rien » prouve que le
 * club a regardé son armoire avant d'écrire au fournisseur.
 */
final class JustificatifArticle
{
    /**
     * @param list<JustificatifTaille> $tailles vide si l'article n'a qu'une déclinaison
     * @param list<string>             $notes   ce qui explique un écart, seulement s'il y en a un
     */
    public function __construct(
        public readonly StockItem $article,
        public readonly int $ilEnFaut,
        public readonly int $onEnA,
        public readonly int $aCommander,
        public readonly array $tailles,
        public readonly array $notes,
        public readonly int $resteApresDistribution,
    ) {}

    /** Rien à acheter : le club a déjà de quoi servir tout le monde. */
    public function couvert(): bool
    {
        return $this->aCommander === 0;
    }
}

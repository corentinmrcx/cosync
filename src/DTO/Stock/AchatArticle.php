<?php declare(strict_types=1);

namespace App\DTO\Stock;

use App\Entity\StockItem;

/**
 * Un article acheté, ses déclinaisons rangées dessous — la forme que prennent aussi bien
 * l'écran « à commander » que le bon envoyé au fournisseur.
 *
 * **Une seule quantité, celle qu'on commande.** Le besoin, le stock et ce qui est déjà en
 * route ont servi à l'obtenir, mais ces documents ne les portent pas : c'est du travail
 * d'inventaire, et il a son écrit — le justificatif de commande, qui aligne « il en faut N,
 * on en a S, on en commande N - S » devant le conseil. Ici on est devant un catalogue
 * fournisseur, on recopie des quantités.
 */
final class AchatArticle
{
    /** @param list<AchatTaille> $tailles au moins une, dans l'ordre du référentiel */
    public function __construct(
        public readonly StockItem $article,
        public readonly array $tailles,
        public readonly int $quantite,
    ) {}

    /**
     * Le détail ne sort que si l'article se décline : une seule taille le répéterait à
     * l'identique une ligne plus bas, et c'est justement le bruit qu'on enlève.
     */
    public function detaille(): bool
    {
        return count($this->tailles) > 1;
    }

    /**
     * La taille d'un article qui n'en a qu'une — elle rejoint alors la ligne d'article, car
     * sur cet écran la taille n'est pas un détail : c'est ce qu'on écrit au fournisseur.
     */
    public function tailleUnique(): ?string
    {
        return $this->detaille() ? null : $this->tailles[0]->etiquette;
    }
}

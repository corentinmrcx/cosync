<?php declare(strict_types=1);

namespace App\DTO\Effectif;

/**
 * Ce qui s'est réellement passé au moment de supprimer, par opposition à ce que l'écran de
 * confirmation annonçait. Les deux peuvent différer : l'analyse est rejouée juste avant la
 * suppression, et une fiche touchée entre-temps (lien envoyé, formulaire rempli depuis un
 * autre onglet) est écartée. Le message de retour doit alors dire laquelle, et pourquoi.
 */
final class ResultatSuppression
{
    /**
     * @param list<string> $refusees « NOM Prénom » suivi du motif, pour chaque fiche épargnée
     */
    public function __construct(
        public readonly int $supprimees,
        public readonly array $refusees = [],
    ) {}
}

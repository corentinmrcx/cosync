<?php declare(strict_types=1);

namespace App\DTO\Planning;

/**
 * Ce que le parseur a compris d'un texte collé, **avant** tout enregistrement.
 *
 * L'aperçu est obligatoire dans le parcours : un collage vient d'un mail ou d'un tableau
 * dont le format n'est jamais garanti, et enregistrer directement remplirait le planning
 * de lignes fausses qu'il faudrait retrouver une à une. L'admin voit ce qui a été compris,
 * et ce qui ne l'a pas été.
 */
final class PlanningCollageApercu
{
    /**
     * @param list<MatchImporteData> $matchs  lignes comprises, prêtes à enregistrer
     * @param list<string>           $ignorees lignes non reconnues, rendues telles quelles
     */
    public function __construct(
        public readonly array $matchs,
        public readonly array $ignorees,
    ) {}

    public function estVide(): bool
    {
        return $this->matchs === [];
    }

    public function aDesRejets(): bool
    {
        return $this->ignorees !== [];
    }
}

<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Ce que l'affectation automatique ferait — ou vient de faire — sur les licenciés
 * encore sans équipe.
 *
 * Le même objet sert d'aperçu avant confirmation et de compte rendu après application :
 * l'admin doit pouvoir vérifier que le résultat correspond à ce qu'on lui avait annoncé.
 */
final class AffectationEquipeApercu
{
    /**
     * @param array<string, int> $parEquipe      nombre de licenciés affectables, par nom d'équipe
     * @param array<string, int> $nonAffectables nombre de licenciés qu'aucune règle ne tranche
     *                                           (aucune équipe, ou plusieurs), par code de catégorie
     */
    public function __construct(
        public readonly array $parEquipe,
        public readonly array $nonAffectables,
    ) {}

    public function total(): int
    {
        return array_sum($this->parEquipe);
    }

    public function totalNonAffectables(): int
    {
        return array_sum($this->nonAffectables);
    }

    /** Rien à proposer et rien à signaler : le bloc d'affectation n'a pas lieu d'être affiché. */
    public function estVide(): bool
    {
        return $this->parEquipe === [] && $this->nonAffectables === [];
    }
}

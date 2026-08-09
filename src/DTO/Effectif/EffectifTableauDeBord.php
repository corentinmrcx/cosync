<?php declare(strict_types=1);

namespace App\DTO\Effectif;

/** Compteurs du hub Effectif : populations et dossiers en attente d'action. */
final class EffectifTableauDeBord
{
    public function __construct(
        public readonly int $nbJoueurs,
        public readonly int $nbDirigeants,
        public readonly int $nbLiensNonEnvoyes,
        public readonly int $nbFormulairesSansReponse,
        public readonly int $nbPaiementsEnAttente,
        public readonly int $nbSignaturesEnAttente,
    ) {}

    /** Dossiers dont le formulaire n'est pas encore complété, quel qu'en soit le motif. */
    public function nbFormulairesEnAttente(): int
    {
        return $this->nbLiensNonEnvoyes + $this->nbFormulairesSansReponse;
    }
}

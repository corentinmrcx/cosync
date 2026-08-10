<?php declare(strict_types=1);

namespace App\DTO\Effectif;

/** Compteurs du hub Effectif : populations et dossiers en attente d'action. */
final class EffectifTableauDeBord
{
    public function __construct(
        public readonly int $nbJoueurs,
        public readonly int $nbDirigeants,
        public readonly int $nbJoueursEnAttente,
        public readonly int $nbDirigeantsEnAttente,
    ) {}

    /**
     * Personnes dont le dossier n'est pas bouclé, tout motif confondu : lien pas encore
     * envoyé, formulaire sans réponse, ou formulaire rempli mais cotisation non encaissée.
     * L'unité est la personne, jamais l'étape — un même joueur ne compte qu'une fois,
     * quel que soit le nombre de choses qu'il lui reste à faire.
     */
    public function nbEnAttenteValidation(): int
    {
        return $this->nbJoueursEnAttente + $this->nbDirigeantsEnAttente;
    }
}

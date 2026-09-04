<?php declare(strict_types=1);

namespace App\DTO\Effectif;

use App\Enum\FicheAction;

/**
 * Ce que l'en-tête d'une fiche — licencié ou dirigeant — doit afficher : une action mise en
 * avant, les autres derrière un menu.
 */
final class FicheActions
{
    /** @param FicheAction[] $secondaires */
    public function __construct(
        public readonly ?FicheAction $principale,
        public readonly array $secondaires,
        /**
         * Pourquoi aucune action n'est mise en avant, quand quelque chose l'empêche.
         *
         * Affiché à la place du bouton : « rien ne s'affiche » n'apprend rien à l'admin qui
         * cherche pourquoi il ne peut pas relancer quelqu'un.
         */
        public readonly ?string $blocage = null,
    ) {}
}

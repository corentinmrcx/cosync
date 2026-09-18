<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationLigneAction;

/**
 * Ce qu'une ligne du suivi propose : **l'étape suivante du parcours**, et — si la ligne en a
 * franchi une — de quoi revenir en arrière.
 *
 * Les deux ne vont pas au même endroit de la ligne. L'étape suivante est une action : elle
 * s'affiche en bouton, dans la colonne d'actions. Le retour, lui, défait un état : il ferme le
 * menu « ⋯ » de la ligne, en rouge sous un filet. Les mettre côte à côte faisait varier la largeur de la colonne d'actions
 * d'une ligne à l'autre et mettait sur le même plan « avancer » et « annuler ».
 */
final class DotationLigneActions
{
    public function __construct(
        public readonly ?DotationLigneAction $principale,
        public readonly ?DotationLigneAction $retour,
    ) {}
}

<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qu'on corrige sur une ligne du suivi des dotations, sans la faire avancer.
 *
 * Contrairement à {@see DotationLigneAction}, ces gestes n'ont **pas d'ordre** : corriger la taille
 * n'est pas une étape avant de corriger le flocage. Ils vivent donc dans le menu « ⋯ » de la ligne,
 * et la saisie s'ouvre dans la cellule de la valeur corrigée.
 *
 * **Les libellés ne sont pas ici** mais dans `admin/dotations/_menu_ligne.html.twig` : ils
 * dépendent de l'état de la ligne (« Saisir » ou « Corriger » le texte à floquer).
 */
enum DotationLigneCorrection: string
{
    case OPTION = 'option';
    case ARTICLE = 'article';
    case TAILLE = 'taille';
    case FLOCAGE = 'flocage';

    /** Le droit qu'exige ce geste — le même que la route qu'il déclenche. */
    public function permission(): Permission
    {
        return match ($this) {
            self::OPTION, self::ARTICLE, self::TAILLE, self::FLOCAGE => Permission::DOTATION_GERER,
        };
    }
}

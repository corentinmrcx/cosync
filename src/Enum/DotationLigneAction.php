<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les gestes possibles sur une ligne du suivi des dotations.
 *
 * Deux registres, et l'écran ne les met pas au même endroit : `PREPARER` et `REMETTRE` font
 * **avancer** la ligne — un seul bouton à la fois, celui de l'étape en cours ; `DEPREPARER` et
 * `ANNULER_REMISE` **défont** ce qu'elle affiche, et s'écrivent contre le badge de statut.
 *
 * **Les libellés ne sont pas ici** mais dans `admin/dotations/_action.html.twig`, avec le
 * balisage : un geste de retour n'a pas de texte du tout — juste une icône et son infobulle —
 * et deux sources de vérité pour un même mot se contrediraient au premier changement.
 */
enum DotationLigneAction: string
{
    case PREPARER = 'preparer';
    case REMETTRE = 'remettre';
    case DEPREPARER = 'depreparer';
    case ANNULER_REMISE = 'annuler_remise';

    /** Défait un état enregistré : jamais mise en avant, toujours en bas du menu, sous un filet. */
    public function estDangereuse(): bool
    {
        return $this === self::DEPREPARER || $this === self::ANNULER_REMISE;
    }

    /**
     * Le droit qu'exige ce geste — le même que la route qu'il déclenche.
     *
     * Remplir un sac et le remettre en décrémentant le stock sont deux fonctions du club :
     * un compte qui ne fait que préparer ne doit pas voir « Marquer remis » dans le menu.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::PREPARER, self::DEPREPARER => Permission::DOTATION_PREPARER,
            self::REMETTRE, self::ANNULER_REMISE => Permission::DOTATION_GERER,
        };
    }
}

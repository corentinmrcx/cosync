<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les gestes possibles depuis la fiche d'un licencié.
 *
 * L'en-tête de la fiche en affichait jusqu'à cinq côte à côte, dont trois en `btn-primary` :
 * l'écran ne disait plus ce qu'il attendait. Cet enum sert à trier — une action mise en avant,
 * le reste dans un menu ({@see \App\Service\Licencie\FicheActionsResolver}).
 *
 * **Les libellés ne sont pas ici** mais dans `admin/licencies/_action.html.twig`, avec le
 * balisage : celui de l'attestation change selon qu'il en existe déjà (« Attestation de
 * paiement » / « Nouvelle attestation »), et deux sources de vérité pour un même texte se
 * seraient contredites au premier changement de mot.
 */
enum FicheAction: string
{
    case MODIFIER = 'modifier';
    case ENVOYER_LIEN = 'envoyer_lien';
    case COMPLETER_AUTORISATIONS = 'completer_autorisations';
    case DEMANDER_SIGNATURE = 'demander_signature';
    case RELANCER_PAIEMENT = 'relancer_paiement';
    case VALIDER_FFF = 'valider_fff';
    case ANNULER_VALIDATION_FFF = 'annuler_validation_fff';
    case ATTESTATION_PAIEMENT = 'attestation_paiement';

    /** Une action qui part par mail n'est pas proposable à qui n'a pas d'adresse. */
    public function exigeUnEmail(): bool
    {
        return match ($this) {
            self::ENVOYER_LIEN, self::COMPLETER_AUTORISATIONS, self::DEMANDER_SIGNATURE, self::RELANCER_PAIEMENT => true,
            default => false,
        };
    }

    /** Défait un état enregistré (§7.6 bis) : jamais mise en avant, toujours en bas du menu. */
    public function estDangereuse(): bool
    {
        return $this === self::ANNULER_VALIDATION_FFF;
    }

    /**
     * Le droit qu'exige ce geste — le même que la route qu'il déclenche.
     *
     * Sans ce lien, la fiche proposerait à la trésorière un bouton « Envoyer le lien » qui
     * répond 403 : le contrôleur refuse déjà, mais l'écran ne le sait pas.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::VALIDER_FFF, self::ANNULER_VALIDATION_FFF => Permission::LICENCE_VALIDER_FFF,
            self::ATTESTATION_PAIEMENT => Permission::PAIEMENT_ATTESTER,
            default => Permission::EFFECTIF_GERER,
        };
    }
}

<?php declare(strict_types=1);

namespace App\Service\Licencie;

use App\DTO\Licencie\FicheActions;
use App\Entity\Licencie;
use App\Enum\FicheAction;
use App\Enum\LicenceStatus;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quelle action la fiche d'un licencié met-elle en avant, et lesquelles range-t-elle ?
 *
 * **Une seule action principale, celle du moment.** C'est la première étape non franchie du
 * parcours : envoyer le lien, compléter, faire signer, valider à la FFF. Le reste reste
 * accessible, mais dans un menu — un écran qui propose cinq boutons ne dit plus lequel compte.
 *
 * La règle vit ici et non dans le template : c'est du métier (l'ordre du parcours, les
 * conditions de chaque geste), et Twig ne doit pas en porter (§7, §10).
 */
final class FicheActionsResolver
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * L'ordre du parcours, qui est aussi l'ordre de priorité : on met en avant ce qui bloque
     * le plus tôt. Une relance qui part par mail passe donc avant la validation FootClubs,
     * qui n'attend que le club et peut se faire n'importe quand.
     */
    private const ORDRE_PARCOURS = [
        FicheAction::ENVOYER_LIEN,
        FicheAction::COMPLETER_AUTORISATIONS,
        FicheAction::DEMANDER_SIGNATURE,
        FicheAction::RELANCER_PAIEMENT,
        FicheAction::VALIDER_FFF,
    ];

    /** Ordre du menu : le geste courant d'abord, le geste destructeur en dernier. */
    private const ORDRE_MENU = [
        FicheAction::MODIFIER,
        FicheAction::ENVOYER_LIEN,
        FicheAction::COMPLETER_AUTORISATIONS,
        FicheAction::DEMANDER_SIGNATURE,
        FicheAction::RELANCER_PAIEMENT,
        FicheAction::VALIDER_FFF,
        FicheAction::ATTESTATION_PAIEMENT,
        FicheAction::ANNULER_VALIDATION_FFF,
    ];

    public function pour(
        Licencie $licencie,
        bool $autorisationsManquantes,
        bool $signatureManquante,
        bool $attestationPossible,
    ): FicheActions {
        $applicables = $this->applicables($licencie, $autorisationsManquantes, $signatureManquante, $attestationPossible);
        $joignable = $licencie->getEmail() !== null;

        // Un geste que le compte n'a pas le droit de jouer disparaît, sans motif : ce n'est pas
        // une étape bloquée, c'est un pan de l'application qu'il ne possède pas (§7.6 quater).
        $applicables = array_values(array_filter(
            $applicables,
            fn (FicheAction $action): bool => $this->security->isGranted($action->permission()->value),
        ));

        $principale = null;
        $blocage = null;

        foreach (self::ORDRE_PARCOURS as $action) {
            if (!in_array($action, $applicables, true)) {
                continue;
            }

            // L'étape du moment part par mail et on n'a pas d'adresse : on ne propose rien,
            // mais on dit pourquoi — sinon l'admin cherche un bouton qui n'existera jamais.
            if ($action->exigeUnEmail() && !$joignable) {
                $blocage = 'Pas d\'email renseigné';
            } else {
                $principale = $action;
            }

            break;
        }

        $secondaires = [];
        foreach (self::ORDRE_MENU as $action) {
            if ($action === $principale || !in_array($action, $applicables, true)) {
                continue;
            }
            // Une action injouable n'a pas plus sa place dans le menu que dans l'en-tête.
            if ($action->exigeUnEmail() && !$joignable) {
                continue;
            }

            $secondaires[] = $action;
        }

        return new FicheActions($principale, $secondaires, $blocage);
    }

    /**
     * Les gestes que l'état du dossier autorise, sans considérer l'adresse email.
     *
     * @return FicheAction[]
     */
    private function applicables(
        Licencie $licencie,
        bool $autorisationsManquantes,
        bool $signatureManquante,
        bool $attestationPossible,
    ): array {
        $statut = $licencie->getDossierClub()?->getStatus();

        $actions = [FicheAction::MODIFIER];

        if ($statut === null || $statut === LicenceStatus::IMPORTED || $statut === LicenceStatus::LINK_SENT) {
            $actions[] = FicheAction::ENVOYER_LIEN;
        }
        if ($autorisationsManquantes) {
            $actions[] = FicheAction::COMPLETER_AUTORISATIONS;
        }
        if ($signatureManquante) {
            $actions[] = FicheAction::DEMANDER_SIGNATURE;
        }
        // Dossier complet, cotisation pas encore enregistrée : le seul geste qui reste,
        // et le seul cas où « relancer » veut dire autre chose que renvoyer le lien.
        // Volontairement **pas** conditionné au délai de la relance automatique : c'est un
        // acte délibéré d'admin, et l'en-tête de la fiche affiche le dernier contact juste
        // au-dessus — on montre l'information, on ne bloque pas la personne.
        if ($statut === LicenceStatus::FORM_COMPLETED) {
            $actions[] = FicheAction::RELANCER_PAIEMENT;
        }
        if ($statut === LicenceStatus::A_VALIDER_FFF) {
            $actions[] = FicheAction::VALIDER_FFF;
        }
        if ($statut === LicenceStatus::VALIDATED) {
            $actions[] = FicheAction::ANNULER_VALIDATION_FFF;
        }
        if ($attestationPossible) {
            $actions[] = FicheAction::ATTESTATION_PAIEMENT;
        }

        return $actions;
    }
}

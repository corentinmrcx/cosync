<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationLigneActions;
use App\DTO\DotationSuiviGroupe;
use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationLigneAction;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quel geste une ligne du suivi propose-t-elle, et lequel la ramène en arrière ?
 *
 * **Le parcours dicte le bouton** : on prépare le sac, puis on le remet — un seul bouton à la
 * fois, celui de l'étape en cours. La règle vit ici et non dans le template : c'est du métier —
 * l'ordre des étapes et les droits que chacune exige (§5, §7).
 *
 * Le geste de retour ({@see DotationLigneActions}) n'est pas rangé avec lui : il ferme le menu
 * « ⋯ » de la ligne, parce qu'il ne fait pas avancer la ligne, il défait ce qu'elle affiche.
 */
final class DotationLigneActionsResolver
{
    public function __construct(private readonly Security $security) {}

    public function pour(DotationBesoin $besoin): DotationLigneActions
    {
        [$principale, $retour] = match ($besoin->getStatut()) {
            DotationBesoinStatut::A_DONNER => [DotationLigneAction::PREPARER, null],
            DotationBesoinStatut::PREPARE => [DotationLigneAction::REMETTRE, DotationLigneAction::DEPREPARER],
            DotationBesoinStatut::DONNE => [null, DotationLigneAction::ANNULER_REMISE],
        };

        // Un geste que le compte ne possède pas disparaît, sans motif : ce n'est pas une étape
        // bloquée, c'est un pan de l'application qui ne le regarde pas — et un bouton qui
        // répondrait « Access Denied » vaut moins que pas de bouton du tout.
        return new DotationLigneActions(
            $principale !== null && $this->possede($principale) ? $principale : null,
            $retour !== null && $this->possede($retour) ? $retour : null,
        );
    }

    /**
     * Actions de tous les besoins affichés, indexées par identifiant — l'écran en rend une par
     * ligne, les résoudre depuis le template y remettrait du métier.
     *
     * @param list<DotationSuiviGroupe> $groupes
     *
     * @return array<int, DotationLigneActions>
     */
    public function parBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                $out[$besoin->getId()] = $this->pour($besoin);
            }
        }

        return $out;
    }

    private function possede(DotationLigneAction $action): bool
    {
        return $this->security->isGranted($action->permission()->value);
    }
}

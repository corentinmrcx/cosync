<?php declare(strict_types=1);

namespace App\Service\Planning;

use App\DTO\Planning\PlanningJournee;
use App\DTO\Planning\PlanningPeriode;
use App\Entity\MatchDomicile;
use App\Entity\Season;
use App\Repository\MatchDomicileRepository;

/**
 * Met en forme ce qui va s'imprimer. N'écrit rien.
 *
 * Le regroupement par journée est fait ici plutôt que dans le template : trois documents
 * partagent la même donnée, et la logique de groupement recopiée trois fois divergerait
 * au premier ajustement — la mairie et les habitants n'auraient plus le même planning.
 */
final class PlanningDocumentPresenter
{
    public function __construct(
        private readonly MatchDomicileRepository $matchRepo,
    ) {}

    /**
     * Les rencontres à imprimer : la période, masqués exclus, triées par date puis heure.
     *
     * La troncature d'un flyer et le placement de son pied ne se décident pas ici — c'est
     * une affaire de millimètres, qui appartient à FlyerGeometrie.
     *
     * @return list<MatchDomicile>
     */
    public function matchs(Season $season, PlanningPeriode $periode): array
    {
        return $this->matchRepo->findPourDocument($season, $periode->du, $periode->au);
    }

    /** @return list<PlanningJournee> */
    public function journees(Season $season, PlanningPeriode $periode): array
    {
        return $this->grouper($this->matchRepo->findPourDocument($season, $periode->du, $periode->au));
    }

    /**
     * @param MatchDomicile[] $matchs déjà triés par date puis heure
     *
     * @return list<PlanningJournee>
     */
    public function grouper(array $matchs): array
    {
        $parJour = [];

        foreach ($matchs as $match) {
            $parJour[$match->getDate()->format('Y-m-d')][] = $match;
        }

        $journees = [];

        foreach ($parJour as $lignes) {
            $journees[] = new PlanningJournee($lignes[0]->getDate(), $lignes);
        }

        return $journees;
    }

    /**
     * Nombre de matchs de la période — l'écran de génération l'annonce **avant** de
     * produire le document. Découvrir un planning vide après l'avoir imprimé, puis se
     * demander si la période est mauvaise ou si le calendrier n'est pas rempli, est le
     * genre d'aller-retour qu'un compteur évite.
     */
    public function compter(Season $season, PlanningPeriode $periode): int
    {
        return count($this->matchRepo->findPourDocument($season, $periode->du, $periode->au));
    }
}

<?php declare(strict_types=1);

namespace App\Service\Planning;

use App\DTO\Planning\PlanningPeriode;

/**
 * La période proposée par défaut à la génération d'un document.
 *
 * Le club imprime son planning en fin de mois pour le mois qui vient : c'est ce mois-là
 * qui est pré-rempli, pas le mois courant à moitié écoulé. Proposer « aujourd'hui →
 * dans 30 jours » donnerait des bornes bâtardes (du 27 au 26) sur un document qu'on
 * annonce comme « le planning de septembre ».
 */
final class PlanningPeriodeResolver
{
    /** Jour du mois à partir duquel on bascule sur le mois suivant. */
    private const BASCULE = 20;

    public function parDefaut(?\DateTimeImmutable $aujourdhui = null): PlanningPeriode
    {
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable())->setTime(0, 0);

        $premierDuMois = (int) $aujourdhui->format('j') >= self::BASCULE
            ? $aujourdhui->modify('first day of next month')
            : $aujourdhui->modify('first day of this month');

        return new PlanningPeriode(
            $premierDuMois,
            $premierDuMois->modify('last day of this month'),
        );
    }

    /**
     * Bornes saisies par l'admin, ramenées à minuit.
     *
     * @throws \DomainException si l'ordre est inversé — mieux vaut le dire que rendre un
     *                          document vide qu'on croirait être un mois sans match
     */
    public function depuis(?\DateTimeImmutable $du, ?\DateTimeImmutable $au): PlanningPeriode
    {
        if ($du === null || $au === null) {
            throw new \DomainException('Les deux dates de la période sont obligatoires.');
        }

        $periode = new PlanningPeriode($du->setTime(0, 0), $au->setTime(0, 0));

        if (!$periode->estValide()) {
            throw new \DomainException('La date de fin doit être postérieure à la date de début.');
        }

        return $periode;
    }
}

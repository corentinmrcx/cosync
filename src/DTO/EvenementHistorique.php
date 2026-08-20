<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Une ligne de la chronologie affichée sur une fiche licencié ou dirigeant.
 */
final class EvenementHistorique
{
    /**
     * Clé de tri, distincte de la date affichée.
     *
     * La plupart des événements sont horodatés et se rangent d'eux-mêmes. Un paiement, lui,
     * porte une date **métier** sans heure — celle du chèque : affichée telle quelle, elle
     * vaut minuit au tri et remonterait avant les événements de la même journée. Il donne
     * donc son horodatage de saisie en clé et garde sa date au libellé.
     */
    public readonly \DateTimeImmutable $triDate;

    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $label,
        public readonly string $who,
        public readonly string $format = 'd/m/Y à H:i',
        ?\DateTimeImmutable $triDate = null,
    ) {
        $this->triDate = $triDate ?? $date;
    }
}

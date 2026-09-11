<?php declare(strict_types=1);

namespace App\DTO\Occupation;

/**
 * L'amplitude horaire d'une grille, en minutes depuis minuit.
 *
 * Elle se **déduit des créneaux** plutôt que d'être figée : une semaine qui commence à 17h
 * imprimée sur une échelle 8h–22h laisserait les deux tiers de la feuille en blanc et
 * écraserait les blocs en bas de page.
 */
final readonly class PlageHoraire
{
    public function __construct(
        public int $debut,
        public int $fin,
    ) {}

    public function duree(): int
    {
        return $this->fin - $this->debut;
    }

    /**
     * Les heures pleines à graduer, bornes comprises — les traits horizontaux de la grille
     * et les libellés de la colonne de gauche.
     *
     * @return list<int> heures, en minutes depuis minuit
     */
    public function graduations(): array
    {
        $graduations = [];

        for ($minute = $this->debut; $minute <= $this->fin; $minute += 60) {
            $graduations[] = $minute;
        }

        return $graduations;
    }

    /** La place d'une minute dans la plage, de 0 (en haut) à 1 (en bas). */
    public function fraction(int $minute): float
    {
        return ($minute - $this->debut) / $this->duree();
    }
}

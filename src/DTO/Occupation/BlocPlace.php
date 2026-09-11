<?php declare(strict_types=1);

namespace App\DTO\Occupation;

use App\Entity\CreneauOccupation;

/**
 * Un créneau et sa place dans sa colonne, en **fractions** de 0 à 1.
 *
 * Sans unité, volontairement : l'écran multiplie par 100 pour en faire des pourcentages,
 * le PDF par la hauteur et la largeur en millimètres de sa grille. C'est ce qui fait que la
 * feuille remise à la mairie montre exactement ce que l'admin a composé — un second calcul
 * de placement, en CSS ou en JavaScript, divergerait au premier ajustement.
 */
final readonly class BlocPlace
{
    public function __construct(
        public CreneauOccupation $creneau,
        public float $haut,
        public float $hauteur,
        public float $gauche,
        public float $largeur,
    ) {}

    public function hautPourcent(): float
    {
        return round($this->haut * 100, 3);
    }

    public function hauteurPourcent(): float
    {
        return round($this->hauteur * 100, 3);
    }

    public function gauchePourcent(): float
    {
        return round($this->gauche * 100, 3);
    }

    public function largeurPourcent(): float
    {
        return round($this->largeur * 100, 3);
    }

    /**
     * Un bloc court n'a pas la place d'afficher ses trois lignes : le terrain passe à la
     * ligne de l'horaire, et sous un quart d'heure il ne reste que l'équipe.
     */
    public function estCourt(): bool
    {
        return $this->creneau->duree() < 60;
    }
}

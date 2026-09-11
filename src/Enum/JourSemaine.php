<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les sept colonnes de la grille d'occupation.
 *
 * Un jour de semaine et non une date : c'est toute la différence entre cette grille et le
 * planning des matchs. Un créneau d'entraînement n'a pas lieu « le mercredi 15 octobre »,
 * il a lieu « le mercredi » — la grille se pose en début de saison et ne se rejoue pas
 * chaque semaine.
 *
 * L'ordre des cas est celui des colonnes du document : la semaine commence le lundi, comme
 * sur tous les plannings que le club affiche.
 */
enum JourSemaine: string
{
    case LUNDI = 'lundi';
    case MARDI = 'mardi';
    case MERCREDI = 'mercredi';
    case JEUDI = 'jeudi';
    case VENDREDI = 'vendredi';
    case SAMEDI = 'samedi';
    case DIMANCHE = 'dimanche';

    public function libelle(): string
    {
        return ucfirst($this->value);
    }

    /** « Lun. » — les colonnes du téléphone, trop étroites pour le nom entier. */
    public function libelleCourt(): string
    {
        return match ($this) {
            self::LUNDI => 'Lun.',
            self::MARDI => 'Mar.',
            self::MERCREDI => 'Mer.',
            self::JEUDI => 'Jeu.',
            self::VENDREDI => 'Ven.',
            self::SAMEDI => 'Sam.',
            self::DIMANCHE => 'Dim.',
        };
    }

    /** Numéro ISO (lundi = 1), qui est aussi le rang de la colonne. */
    public function numero(): int
    {
        return match ($this) {
            self::LUNDI => 1,
            self::MARDI => 2,
            self::MERCREDI => 3,
            self::JEUDI => 4,
            self::VENDREDI => 5,
            self::SAMEDI => 6,
            self::DIMANCHE => 7,
        };
    }

    public function estWeekend(): bool
    {
        return $this === self::SAMEDI || $this === self::DIMANCHE;
    }
}

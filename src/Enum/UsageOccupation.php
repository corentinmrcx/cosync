<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qui se passe sur le créneau — la seule chose qui colore un bloc de la grille.
 *
 * La mairie ne lit pas ce document pour savoir qui joue : elle le lit pour savoir quand le
 * terrain est pris, et ce qu'il s'y passe change ce qu'elle en attend. Un entraînement
 * n'amène personne ; une rencontre amène du public et des voitures.
 *
 * Les couleurs sont écrites **en dur** parce qu'elles servent aussi au PDF : DomPDF
 * n'applique pas les variables CSS de `app.css`. Les mêmes valeurs sont reprises en
 * variables dans `pages/occupation.css` pour l'écran — c'est le seul endroit du projet où
 * une couleur se répète, et c'est le rendu papier qui l'impose.
 */
enum UsageOccupation: string
{
    case ENTRAINEMENT = 'entrainement';
    case MATCH = 'match';
    case EVENEMENT = 'evenement';

    public function libelle(): string
    {
        return match ($this) {
            self::ENTRAINEMENT => 'Entraînement',
            self::MATCH => 'Match',
            self::EVENEMENT => 'Événement',
        };
    }

    /** Fond du bloc. */
    public function couleurFond(): string
    {
        return match ($this) {
            self::ENTRAINEMENT => '#eff6ff',
            self::MATCH => '#ffe5e5',
            self::EVENEMENT => '#fffbeb',
        };
    }

    /** Filet gauche et texte du bloc — la teinte qui le distingue de loin. */
    public function couleurTrait(): string
    {
        return match ($this) {
            self::ENTRAINEMENT => '#3b82f6',
            self::MATCH => '#ff3131',
            self::EVENEMENT => '#f59e0b',
        };
    }
}

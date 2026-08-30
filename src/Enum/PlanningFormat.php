<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les trois tirages d'un même planning. Ce n'est pas une préférence de mise en page :
 * chaque format a son destinataire et son geste.
 *
 * - `A4_MAIRIE` : l'employé communal qui tond le terrain. Dense, une ligne par match.
 * - `A5_FLYER` : une boîte aux lettres. Peu de lignes, grosse typo.
 * - `A4_DUO` : la même chose, deux flyers côte à côte sur une A4 **paysage**, à couper en
 *   deux. C'est le tirage réel : imprimer les flyers un par un gâche la moitié du papier.
 */
enum PlanningFormat: string
{
    case A4_MAIRIE = 'a4_mairie';
    case A5_FLYER = 'a5_flyer';
    case A4_DUO = 'a4_duo';

    public function label(): string
    {
        return match ($this) {
            self::A4_MAIRIE => 'A4 — planning mairie',
            self::A5_FLYER => 'A5 — flyer seul',
            self::A4_DUO => 'A4 — deux flyers A5 côte à côte',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::A4_MAIRIE => 'Feuille de service pour la tonte du terrain : toutes les rencontres, dates et heures.',
            self::A5_FLYER => 'Un flyer par page A5, pour une imprimante réglée en A5.',
            self::A4_DUO => 'Deux flyers identiques sur une A4 paysage, à couper au milieu.',
        };
    }

    public function template(): string
    {
        return match ($this) {
            self::A4_MAIRIE => 'pdf/planning/mairie.html.twig',
            self::A5_FLYER => 'pdf/planning/flyer_a5.html.twig',
            self::A4_DUO => 'pdf/planning/flyer_duo.html.twig',
        };
    }

    /** Format papier au sens DomPDF. */
    public function papier(): string
    {
        return match ($this) {
            self::A5_FLYER => 'A5',
            default => 'A4',
        };
    }

    public function orientation(): string
    {
        return match ($this) {
            self::A4_DUO => 'landscape',
            default => 'portrait',
        };
    }

    /**
     * Les deux tirages destinés aux boîtes aux lettres partagent la même page A5, donc la
     * même géométrie : c'est FlyerGeometrie qui décide de ce qui y tient et où tombe le
     * bloc de contacts. La feuille de la mairie, elle, prend toutes les rencontres et
     * continue sur une seconde page.
     */
    public function estFlyer(): bool
    {
        return $this !== self::A4_MAIRIE;
    }

    /** Segment repris dans le nom du fichier archivé sur Drive. */
    public function suffixeFichier(): string
    {
        return match ($this) {
            self::A4_MAIRIE => 'mairie',
            self::A5_FLYER => 'flyer',
            self::A4_DUO => 'flyer-duo',
        };
    }
}

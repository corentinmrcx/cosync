<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est une ligne de dotation, du carton au licencié.
 *
 * `PREPARE` n'est pas un simple affichage : c'est le **point de gel** de la ligne. Tant qu'un
 * besoin est « à donner », l'automate le rattrape à chaque affichage du suivi — la taille se
 * réaligne sur le dossier, l'écoulement rearbitre le carton, le recalcul le supprime si le kit
 * a changé. Une fois le sac fait, ces trois-là mentiraient sur ce qu'il contient : le besoin
 * préparé ne bouge plus qu'à la main. Comme tout verrou du projet, il a sa sortie —
 * « Dé-préparer ».
 *
 * Le stock, lui, ne bouge qu'à la remise : un sac préparé est encore dans l'armoire du club.
 */
enum DotationBesoinStatut: string
{
    case A_DONNER = 'a_donner';
    case PREPARE = 'prepare';
    case DONNE = 'donne';

    public function label(): string
    {
        return match ($this) {
            self::A_DONNER => 'À donner',
            self::PREPARE => 'Préparé',
            self::DONNE => 'Donné',
        };
    }

    /** Remis à la personne : la sortie de stock est faite. */
    public function estRemis(): bool
    {
        return $this === self::DONNE;
    }

    /** Mis de côté, prêt à être remis — toujours dans l'armoire du club. */
    public function estPrepare(): bool
    {
        return $this === self::PREPARE;
    }

    /**
     * Pas encore remis : la ligne pèse toujours sur les achats et sur le stock à venir.
     * C'est ce que lisent `AchatService` et la répartition d'écoulement — pas `A_DONNER`,
     * qui laisserait croire qu'un sac préparé est déjà servi et ferait sous-commander.
     */
    public function resteAServir(): bool
    {
        return $this !== self::DONNE;
    }

    /**
     * L'automate peut-il encore rattraper cette ligne ? Vrai du seul `A_DONNER` : taille
     * réalignée sur le dossier, carton d'écoulement rearbitré, purge au recalcul.
     */
    public function suitLeRecalcul(): bool
    {
        return $this === self::A_DONNER;
    }

    /**
     * Le contenu de la ligne est-il engagé — dans un sac, ou dans les mains du licencié ?
     * L'autre face de {@see suitLeRecalcul()}, du point de vue de l'admin plutôt que de
     * l'automate : c'est elle que lisent les écrans pour cacher un crayon qui ne mènerait
     * qu'à un refus.
     */
    public function contenuFige(): bool
    {
        return !$this->suitLeRecalcul();
    }

    /**
     * Pourquoi le contenu de cette ligne ne se change plus — l'option retenue, le carton
     * servi, le texte floqué —, et quel geste défaire d'abord. Null tant que la ligne est
     * libre, ce qui recouvre exactement {@see suitLeRecalcul()} : une ligne que l'automate
     * ne rattrape plus est une ligne dont un humain a engagé le contenu, dans un sac ou
     * dans les mains du licencié.
     *
     * La **taille**, elle, se corrige à tout statut : elle dit l'article à échanger, et le
     * service rejoue le mouvement de stock quand il le faut.
     *
     * @param string $geste l'infinitif attendu par la phrase — « changer l'option »
     */
    public function motifDeBlocage(string $geste): ?string
    {
        return match ($this) {
            self::A_DONNER => null,
            self::PREPARE => sprintf('Cet article est déjà préparé. Dé-préparez la ligne pour %s.', $geste),
            self::DONNE => sprintf('Cet article a déjà été remis. Annulez d\'abord la remise pour %s.', $geste),
        };
    }
}

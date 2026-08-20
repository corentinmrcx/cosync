<?php declare(strict_types=1);

namespace App\Enum;

/**
 * D'où sort l'article d'un besoin encore à remettre : de l'armoire, d'une commande en
 * route, ou de rien du tout.
 *
 * Le suivi disait ce qu'il faut donner, jamais s'il est déjà là. Celui qui prépare une
 * remise ouvrait donc les cartons pour le découvrir, et rien ne distinguait une ligne
 * servie par l'ancien stock d'une ligne qui attend un colis.
 */
enum DotationProvenance: string
{
    case EN_STOCK = 'en_stock';
    case COMMANDE = 'commande';
    case A_COMMANDER = 'a_commander';

    public function label(): string
    {
        return match ($this) {
            self::EN_STOCK => 'Stock',
            self::COMMANDE => 'Commandé',
            self::A_COMMANDER => 'À commander',
        };
    }

    /** Ce que la pastille explique en infobulle, avant la désignation de l'article. */
    public function explication(): string
    {
        return match ($this) {
            self::EN_STOCK => 'À prendre dans le stock',
            self::COMMANDE => 'Pas en stock — couvert par une commande en cours',
            self::A_COMMANDER => 'Ni en stock, ni commandé',
        };
    }
}

<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est la dotation d'une personne, vue depuis sa fiche.
 */
enum DotationAvancementStatut: string
{
    /** Un kit s'applique, mais les besoins ne sont pas encore matérialisés (personne non validée). */
    case PREVUE = 'prevue';
    case ATTENTE = 'attente';
    /** Tout est mis de côté, rien n'est encore parti : il ne reste qu'à la remettre. */
    case PREPAREE = 'preparee';
    case PARTIELLE = 'partielle';
    case REMISE = 'remise';

    public function badgeVariant(): string
    {
        return match ($this) {
            self::REMISE => 'validated',
            self::PREPAREE => 'a-valider',
            self::PARTIELLE, self::ATTENTE => 'completed',
            self::PREVUE => 'sent',
        };
    }
}

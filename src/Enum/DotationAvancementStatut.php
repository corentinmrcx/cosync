<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est la dotation d'une personne, vue depuis sa fiche.
 */
enum DotationAvancementStatut: string
{
    /** Un kit s'applique, mais les besoins ne sont pas encore matérialisés (personne non validée). */
    case A_PREPARER = 'a_preparer';
    case ATTENTE = 'attente';
    case PARTIELLE = 'partielle';
    case REMISE = 'remise';

    public function badgeVariant(): string
    {
        return match ($this) {
            self::REMISE => 'validated',
            self::PARTIELLE, self::ATTENTE => 'completed',
            self::A_PREPARER => 'sent',
        };
    }
}

<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est un détenteur vis-à-vis de l'attestation d'une saison donnée.
 *
 * L'engagement se rejoue chaque année : une attestation signée en 2025-2026 ne dit
 * rien de 2026-2027, et le détenteur y retombe donc en NON_SIGNEE. C'est voulu —
 * c'est tout l'objet du renouvellement annuel.
 */
enum CleAttestationEtat: string
{
    case SIGNEE = 'signee';
    /** Signée cette saison, mais une clé a été remise depuis : le nombre attesté est dépassé */
    case A_RENOUVELER = 'a_renouveler';
    case LIEN_ENVOYE = 'lien_envoye';
    case NON_SIGNEE = 'non_signee';

    public function label(): string
    {
        return match ($this) {
            self::SIGNEE => 'Signée',
            self::A_RENOUVELER => 'À renouveler',
            self::LIEN_ENVOYE => 'Lien envoyé',
            self::NON_SIGNEE => 'Non signée',
        };
    }

    /** L'engagement de la saison est-il valable en l'état ? */
    public function estAJour(): bool
    {
        return $this === self::SIGNEE;
    }
}

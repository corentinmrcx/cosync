<?php declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Fenêtre de validité des liens publics envoyés par mail (inscription, formulaire dirigeant,
 * attestation de clés).
 */
final class LienPublic
{
    public const VALIDITE_JOURS = 30;

    public static function expiration(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('+%d days', self::VALIDITE_JOURS));
    }
}

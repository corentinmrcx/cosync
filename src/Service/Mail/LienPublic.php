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

    /**
     * Date d'envoi déduite de l'expiration.
     *
     * Le dirigeant ne porte pas de date d'envoi ; on la retrouve en retranchant la fenêtre.
     * Approximation assumée : rouvrir la fenêtre décale la date affichée.
     */
    public static function envoiDeduitDe(\DateTimeImmutable $expiration): \DateTimeImmutable
    {
        return $expiration->modify(sprintf('-%d days', self::VALIDITE_JOURS));
    }
}

<?php declare(strict_types=1);

namespace App\DTO;

/**
 * La dernière fois que le club a écrit à quelqu'un, prêt à afficher.
 *
 * Existe pour que la fiche puisse répondre d'un coup d'œil à « est-ce que je viens de le
 * relancer ? ». C'est la garantie qu'un mail automatique n'est pas suivi d'une relance à
 * la main quelques heures plus tard — et l'ancre du délai de relance (cf. RelanceResolver).
 */
final class DernierContact
{
    public readonly int $joursEcoules;

    public function __construct(
        public readonly \DateTimeImmutable $date,
        \DateTimeImmutable $maintenant = new \DateTimeImmutable(),
    ) {
        // En jours calendaires, pas en multiples de 24 h : un mail parti hier à 23 h date
        // d'« hier », même s'il n'a que deux heures.
        $this->joursEcoules = (int) $this->date->setTime(0, 0)->diff($maintenant->setTime(0, 0))->days;
    }

    public function anciennete(): string
    {
        return match ($this->joursEcoules) {
            0 => 'aujourd\'hui',
            1 => 'hier',
            default => sprintf('il y a %d jours', $this->joursEcoules),
        };
    }

    public function resume(): string
    {
        return sprintf('%s (%s)', $this->date->format('d/m/Y'), $this->anciennete());
    }
}

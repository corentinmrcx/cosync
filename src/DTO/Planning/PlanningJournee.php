<?php declare(strict_types=1);

namespace App\DTO\Planning;

use App\Entity\MatchDomicile;

/**
 * Les matchs d'une même journée, regroupés.
 *
 * C'est la maille utile des deux documents, et pour la même raison de terrain : la mairie
 * tond **une journée**, pas un match, et un habitant retient « le dimanche 20 » plutôt
 * qu'une liste de lignes. Répéter la date sur chaque ligne obligeait l'employé communal à
 * la relire pour savoir si deux rencontres tombaient le même jour.
 */
final class PlanningJournee
{
    /** @param list<MatchDomicile> $matchs */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly array $matchs,
    ) {}

    public function estWeekend(): bool
    {
        return in_array((int) $this->date->format('N'), [6, 7], true);
    }

    /** L'heure du premier coup d'envoi, celle qui commande la tonte. */
    public function premiereHeure(): ?string
    {
        foreach ($this->matchs as $match) {
            if ($match->getHeure() !== null) {
                return $match->getHeure();
            }
        }

        return null;
    }

    public function nombreDeMatchs(): int
    {
        return count($this->matchs);
    }
}

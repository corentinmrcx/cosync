<?php declare(strict_types=1);

namespace App\DTO\Occupation;

/**
 * La semaine type, prête à dessiner : une amplitude horaire et sept colonnes.
 *
 * Le même objet sert à l'écran d'administration et au PDF de la mairie.
 */
final readonly class GrilleOccupation
{
    /** @param list<ColonneJour> $colonnes */
    public function __construct(
        public PlageHoraire $plage,
        public array $colonnes,
    ) {}

    public function estVide(): bool
    {
        return $this->nombreCreneaux() === 0;
    }

    public function nombreCreneaux(): int
    {
        return array_sum(array_map(static fn (ColonneJour $c): int => count($c->blocs), $this->colonnes));
    }
}

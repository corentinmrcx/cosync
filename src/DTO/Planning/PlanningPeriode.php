<?php declare(strict_types=1);

namespace App\DTO\Planning;

/**
 * Les bornes d'un document, inclusives des deux côtés.
 *
 * La mairie et les boîtes aux lettres n'ont pas le même horizon : c'est la période, et
 * non la saison entière, qui définit ce qui s'imprime.
 */
final class PlanningPeriode
{
    public function __construct(
        public readonly \DateTimeImmutable $du,
        public readonly \DateTimeImmutable $au,
    ) {}

    public function estValide(): bool
    {
        return $this->du <= $this->au;
    }

    /** Segment de nom de fichier : `2026-09-01_2026-09-30`. */
    public function slug(): string
    {
        return $this->du->format('Y-m-d') . '_' . $this->au->format('Y-m-d');
    }
}

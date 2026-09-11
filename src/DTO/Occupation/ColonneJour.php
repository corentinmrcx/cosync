<?php declare(strict_types=1);

namespace App\DTO\Occupation;

use App\Enum\JourSemaine;

/** Une colonne de la grille : un jour de la semaine et ce qui s'y joue, déjà placé. */
final readonly class ColonneJour
{
    /** @param list<BlocPlace> $blocs */
    public function __construct(
        public JourSemaine $jour,
        public array $blocs,
    ) {}

    public function estVide(): bool
    {
        return $this->blocs === [];
    }
}

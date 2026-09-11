<?php declare(strict_types=1);

namespace App\DTO\Occupation;

/** Ce que la reprise d'une grille a produit, dit à l'admin en une phrase. */
final readonly class RepriseGrilleResultat
{
    public function __construct(
        public string $sourceLabel,
        public int $reprises,
        public int $sansEquipe,
    ) {}

    public function resume(): string
    {
        $resume = sprintf(
            '%d créneau%s repris de la saison %s.',
            $this->reprises,
            $this->reprises > 1 ? 'x' : '',
            $this->sourceLabel,
        );

        if ($this->sansEquipe > 0) {
            $resume .= $this->sansEquipe > 1
                ? sprintf(' %d restent sans équipe : la leur n\'existe pas dans cette saison.', $this->sansEquipe)
                : ' 1 reste sans équipe : la sienne n\'existe pas dans cette saison.';
        }

        return $resume;
    }
}

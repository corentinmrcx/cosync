<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationAvancementStatut;

/**
 * Avancement de la dotation d'une personne : le badge de sa fiche.
 */
final class DotationAvancement
{
    public function __construct(
        public readonly DotationAvancementStatut $statut,
        public readonly int $donnes,
        public readonly int $total,
    ) {}

    public function label(): string
    {
        return match ($this->statut) {
            DotationAvancementStatut::REMISE => 'Dotation remise',
            DotationAvancementStatut::PARTIELLE => sprintf('Dotation %d/%d', $this->donnes, $this->total),
            DotationAvancementStatut::ATTENTE => 'Dotation à remettre',
            DotationAvancementStatut::A_PREPARER => 'Dotation prévue',
        };
    }
}

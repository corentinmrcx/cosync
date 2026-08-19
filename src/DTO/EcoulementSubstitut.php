<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\StockItem;

/**
 * Un ancien stock servi à la place de l'article principal, et l'état de son écoulement.
 *
 * `restant` à zéro ne clôt rien : la règle reste déclarée, elle ne trouve simplement plus
 * rien à servir et l'appli recommande l'article principal d'elle-même. C'est ce qui permet
 * de la laisser en place sans surveiller les cartons — mais l'écran doit le dire, sans quoi
 * la ligne se lit comme une transition encore en cours.
 */
final class EcoulementSubstitut
{
    public function __construct(
        public readonly StockItem $item,
        public readonly int $restant,
        public readonly int $dotationsServies,
    ) {}

    public function estEpuise(): bool
    {
        return $this->restant <= 0;
    }
}

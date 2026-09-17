<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

use App\Entity\StockItem;

/**
 * Une chose que le kit promet : un article, ou un choix entre plusieurs.
 *
 * Les options d'un même groupe de choix tiennent dans **une seule** entrée, et c'est ce qui
 * rend la lecture juste : listées à la suite comme des articles ordinaires, elles se
 * cumulaient dans la tête du lecteur — deux sacs par joueur au lieu d'un.
 */
final class JustificatifKitEntree
{
    /** @param list<StockItem> $options un seul élément hors groupe de choix */
    public function __construct(
        public readonly int $quantite,
        public readonly array $options,
        public readonly bool $obligatoire,
    ) {}

    public function auChoix(): bool
    {
        return count($this->options) > 1;
    }

    public function premier(): StockItem
    {
        return $this->options[0];
    }
}

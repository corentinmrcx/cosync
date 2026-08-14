<?php declare(strict_types=1);

namespace App\DTO\Stock;

use App\Entity\StockItem;
use App\Enum\StockAlerteNiveau;

/** Une ligne du tableau de gestion du stock. */
final class StockLigne
{
    /**
     * @param list<StockTailleLigne> $tailles
     * @param array<string, int>     $taillesMap     taille nommée => stock, pour la modale de mouvement
     * @param list<string>           $taillesOptions tailles proposées à la saisie d'un mouvement
     */
    public function __construct(
        public readonly StockItem $item,
        public readonly int $stock,
        public readonly StockAlerteNiveau $niveau,
        public readonly array $tailles,
        public readonly array $taillesMap,
        public readonly array $taillesOptions,
        /** Équipement sans type de vêtement : la modale invite à le renseigner. */
        public readonly bool $typeVetementARenseigner,
    ) {}

    public function hasTailles(): bool
    {
        return $this->taillesMap !== [];
    }
}

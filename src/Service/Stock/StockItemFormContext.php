<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Repository\StockItemRepository;
use App\Service\Referentiel\Tailles;

/**
 * Listes proposées par le formulaire article. Les valeurs déjà saisies sont fusionnées avec
 * le référentiel : le club peut avoir en stock une taille que le référentiel ne prévoit pas.
 */
final class StockItemFormContext
{
    /** @var list<string> */
    private const CONTENANCES_EPICERIE = ['25cl', '33cl', '50cl', '75cl', '1L', '1,5L', '2L'];

    public function __construct(
        private readonly StockItemRepository $itemRepository,
    ) {}

    /** @return array<string, mixed> */
    public function build(?StockItem $item): array
    {
        return [
            'item' => $item,
            'title' => $item !== null ? 'Modifier ' . $item->getNom() : 'Nouvel article',
            'kinds' => StockItemKind::cases(),
            'vetementTypes' => StockItemVetementType::cases(),
            'marques' => $this->itemRepository->findDistinctMarques(),
            'taillesEquip' => $this->fusionne(StockItemKind::EQUIPEMENT, Tailles::toutes()),
            'contenances' => $this->fusionne(StockItemKind::EPICERIE, self::CONTENANCES_EPICERIE),
            'couleurs' => $this->itemRepository->findDistinctCouleurs(),
        ];
    }

    /**
     * @param list<string> $referentiel
     *
     * @return list<string>
     */
    private function fusionne(StockItemKind $kind, array $referentiel): array
    {
        return array_values(array_unique(array_merge(
            $this->itemRepository->findDistinctTaillesByKind($kind),
            $referentiel,
        )));
    }
}

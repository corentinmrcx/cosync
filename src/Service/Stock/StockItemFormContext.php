<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Repository\GrilleTailleRepository;
use App\Repository\StockItemRepository;

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
        private readonly GrilleTailleRepository $grilleRepository,
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
            // Pas de liste de tailles ici : un équipement n'a pas de taille figée, ses
            // déclinaisons se saisissent mouvement par mouvement.
            'contenances' => $this->fusionne(StockItemKind::EPICERIE, self::CONTENANCES_EPICERIE),
            'couleurs' => $this->itemRepository->findDistinctCouleurs(),
            // Toutes les grilles : le formulaire ne garde que celles de l'échelle choisie,
            // qui se décide dans le même écran.
            'grilles' => $this->grilleRepository->findAllOrdered(),
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

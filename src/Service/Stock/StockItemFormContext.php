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
            'ciblesEcoulement' => $this->ciblesEcoulement($item),
            // Ce que cet article-ci écoule déjà : affiché en lecture seule côté article de
            // kit, pour que l'admin voie la transition depuis les deux bouts.
            'substituts' => $item !== null ? $this->itemRepository->findSubstituts($item) : [],
        ];
    }

    /**
     * Articles de kit que celui-ci peut déclarer écouler. La cible déjà enregistrée y est
     * réinjectée même archivée : sans elle, rouvrir la fiche pour changer une couleur
     * effacerait silencieusement la règle d'écoulement.
     *
     * @return list<StockItem>
     */
    private function ciblesEcoulement(?StockItem $item): array
    {
        $cibles = $this->itemRepository->findCiblesEcoulementPossibles($item);
        $actuelle = $item?->getRemplaceArticle();

        if ($actuelle === null) {
            return $cibles;
        }

        foreach ($cibles as $cible) {
            if ($cible->getId() === $actuelle->getId()) {
                return $cibles;
            }
        }

        return [$actuelle, ...$cibles];
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

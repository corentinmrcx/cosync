<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\EcoulementCorrespondance;
use App\DTO\EcoulementSubstitut;
use App\Entity\StockItem;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;

/**
 * Met en forme les transitions de fournisseur pour l'écran de correspondances. Lecture
 * seule : la règle s'écrit par {@see StockItemService::appliquerEcoulement()}.
 *
 * L'écran retourne le sens de la donnée. En base, c'est l'article écoulé qui désigne celui
 * qu'il remplace — une règle par carton, posée une fois pour le club. À l'écran, c'est
 * l'article principal qui mène, avec ses anciens stocks fléchés en dessous : c'est ainsi que
 * la décision se prend, et la déclarer dans l'autre sens était le point de confusion.
 */
final class EcoulementPresenter
{
    public function __construct(
        private readonly StockItemRepository $itemRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly DotationBesoinRepository $besoinRepository,
    ) {}

    /** @return list<EcoulementCorrespondance> */
    public function correspondances(): array
    {
        return array_map(
            $this->correspondance(...),
            $this->itemRepository->findArticlesAvecSubstituts(),
        );
    }

    /**
     * Articles qui peuvent devenir principaux d'une nouvelle correspondance : ceux qu'aucune
     * n'a déjà, un article ne menant qu'une transition à la fois.
     *
     * @param list<EcoulementCorrespondance> $correspondances
     *
     * @return list<StockItem>
     */
    public function principauxPossibles(array $correspondances): array
    {
        $deja = array_map(
            static fn (EcoulementCorrespondance $c): int => $c->principal->getId(),
            $correspondances,
        );

        return array_values(array_filter(
            $this->itemRepository->findCiblesEcoulementPossibles(null),
            static fn (StockItem $item): bool => !in_array($item->getId(), $deja, true),
        ));
    }

    /**
     * Vivier complet du formulaire de création, qui ne connaît pas encore son principal :
     * il restreint au bon type de vêtement côté client, et le service refait le contrôle.
     *
     * @return list<StockItem>
     */
    public function candidatsPossibles(): array
    {
        return $this->itemRepository->findArticlesEcoulablesVers(null);
    }

    /**
     * Candidats à l'écoulement, correspondance par correspondance : chaque bloc n'ouvre que
     * sur les articles du même type de vêtement que son principal.
     *
     * @param list<EcoulementCorrespondance> $correspondances
     *
     * @return array<int, list<StockItem>> indexé par id d'article principal
     */
    public function candidatsParPrincipal(array $correspondances): array
    {
        $out = [];

        foreach ($correspondances as $correspondance) {
            $out[$correspondance->principal->getId()] = $this->itemRepository
                ->findArticlesEcoulablesVers($correspondance->principal);
        }

        return $out;
    }

    private function correspondance(StockItem $principal): EcoulementCorrespondance
    {
        return new EcoulementCorrespondance(
            $principal,
            $this->movementRepository->getCurrentStock($principal),
            array_map($this->substitut(...), $this->itemRepository->findSubstituts($principal)),
        );
    }

    private function substitut(StockItem $item): EcoulementSubstitut
    {
        return new EcoulementSubstitut(
            $item,
            $this->movementRepository->getCurrentStock($item),
            $this->besoinRepository->countByArticleEcoulement($item),
        );
    }
}

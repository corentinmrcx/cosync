<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use App\Repository\StockItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixe à la main l'article servi sur une ligne de dotation, quand l'admin veut décider
 * lui-même de qui reçoit l'ancien stock — garantir le neuf à un nouveau licencié, ou au
 * contraire écouler une taille précise sur quelqu'un qui repasse au local.
 *
 * Pendant du verrou de taille (`DotationBesoin.tailleManuelle`) : une fois épinglée, la ligne
 * n'est plus arbitrée par `DotationEcoulementAllocator`. Une seule exception, portée par
 * l'allocateur : un épinglage que le stock ne couvre plus est relâché — le club ne peut pas
 * remettre ce qu'il n'a pas.
 */
final class DotationEcoulementService
{
    /** Valeur du sélecteur qui rend la ligne à l'arbitrage automatique. */
    public const AUTO = 'auto';

    /** @var array<int, list<StockItem>> Substituts par article du kit — le suivi rappelle par ligne */
    private array $substitutsCache = [];

    public function __construct(
        private readonly StockItemRepository $itemRepository,
        private readonly DotationEcoulementAllocator $allocator,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param string $choix `auto`, l'id de l'article du kit, ou celui d'un article d'écoulement
     *
     * @throws \DomainException si l'article a déjà été remis ou si le choix n'en est pas un
     */
    public function fixerArticle(DotationBesoin $besoin, string $choix): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            throw new \DomainException('Cet article a déjà été remis. Annulez d\'abord la remise pour changer l\'article servi.');
        }

        if ($choix === self::AUTO) {
            $besoin->setArticleManuel(false);
        } elseif ((int) $choix === $besoin->getStockItem()->getId()) {
            $besoin->setArticleEcoulement(null)->setArticleManuel(true);
        } else {
            $besoin->setArticleEcoulement($this->substitutAdmis($besoin, (int) $choix))->setArticleManuel(true);
        }

        $this->em->flush();

        // Repasse sur toute la saison : la place libérée par cette ligne revient à quelqu'un
        // d'autre, et l'article qu'elle vient de réserver sort du pool des autres.
        $this->allocator->allouer($besoin->getSeason());
    }

    /**
     * Articles proposés au sélecteur : celui du kit d'abord, puis ceux qui l'écoulent. Liste
     * vide quand aucun écoulement ne concerne cette ligne — le sélecteur ne s'affiche pas.
     *
     * @return list<StockItem>
     */
    public function articlesPossibles(DotationBesoin $besoin): array
    {
        $substituts = $this->substitutsDe($besoin);

        return $substituts === [] ? [] : [$besoin->getStockItem(), ...$substituts];
    }

    /**
     * Articles proposés, indexés par id de besoin — le suivi en a besoin pour toutes ses
     * lignes d'un coup.
     *
     * @param \App\DTO\DotationSuiviGroupe[] $groupes
     *
     * @return array<int, list<StockItem>>
     */
    public function articlesParBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                $possibles = $this->articlesPossibles($besoin);
                if ($possibles !== []) {
                    $out[$besoin->getId()] = $possibles;
                }
            }
        }

        return $out;
    }

    /** @return list<StockItem> */
    private function substitutsDe(DotationBesoin $besoin): array
    {
        $item = $besoin->getStockItem();

        return $this->substitutsCache[$item->getId()] ??= $this->itemRepository->findSubstituts($item);
    }

    /** @throws \DomainException */
    private function substitutAdmis(DotationBesoin $besoin, int $id): StockItem
    {
        foreach ($this->substitutsDe($besoin) as $substitut) {
            if ($substitut->getId() === $id) {
                return $substitut;
            }
        }

        throw new \DomainException('Cet article n\'écoule pas celui du kit : il ne peut pas être servi à sa place.');
    }
}

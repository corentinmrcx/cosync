<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\Stock\StockTailleResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Répartit le stock des articles en cours d'écoulement sur les besoins encore à remettre.
 *
 * Le club change de fournisseur sans jeter ce qui reste de l'ancien : tant qu'il y a des
 * chaussettes Nike dans la bonne taille, on les sert à la place des ERIMA du kit, et on ne
 * commande que le reste. Sans cet arbitrage, le besoin porte l'article du kit, `AchatService`
 * ne déduit que le stock de CET article-là, et le club rachète du neuf par-dessus un carton
 * plein.
 *
 * Passe saison entière, idempotente, jouée avant chaque lecture du suivi et des achats — même
 * fonctionnement que `DotationBesoinSynchronizer::syncTaillesFromDossiers()`, et pour la même
 * raison : l'arbitrage dépend d'un stock qui bouge, il se refait plutôt qu'il ne se mémorise.
 *
 * Trois règles tiennent l'ensemble, et aucune n'est négociable :
 *
 * - **jamais au-delà du stock**. C'est ce qui garantit que le besoin servi par un substitut
 *   est toujours couvert, donc qu'`AchatService` ne proposera jamais de racheter un article
 *   d'écoulement. Un article épinglé à la main ne fait pas exception : si le stock ne suit
 *   plus, la ligne revient à l'article du kit et se commande normalement ;
 * - **jamais de moitié**. Un besoin de 2 avec une seule paire restante reste entièrement sur
 *   l'article du kit : couper un besoin en deux casserait la remise et le mouvement de stock ;
 * - **jamais de taille approchée**. Une taille que la grille du substitut ne couvre pas écarte
 *   le substitut, elle n'en invente pas une voisine.
 *
 * L'ordre de service est celui de création des besoins — premier inscrit, premier servi. Il
 * doit rester déterministe : c'est ce qui fait que deux écrans consécutifs annoncent la même
 * chose à l'admin.
 */
final class DotationEcoulementAllocator
{
    /** @var array<int, list<StockItem>> Substituts par article officiel (scope requête) */
    private array $substitutsCache = [];

    /** @var array<int, array<string, int>> Stock restant à répartir, par article puis taille */
    private array $pool = [];

    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockTailleResolver $tailles,
        private readonly DotationResolver $resolver,
        private readonly EntityManagerInterface $em,
    ) {}

    public function allouer(Season $season): void
    {
        $this->pool = [];
        $changed = false;

        foreach ($this->besoinsAServir($season) as $besoin) {
            $changed = $this->arbitrer($besoin) || $changed;
        }

        if ($changed) {
            $this->em->flush();
        }
    }

    /**
     * Besoins encore à remettre, dans l'ordre de leur création. Les besoins déjà donnés sont
     * hors sujet : leur sortie de stock est faite, elle est déjà déduite du pool.
     *
     * @return list<DotationBesoin>
     */
    private function besoinsAServir(Season $season): array
    {
        $besoins = $this->besoinRepository->findADonnerBySeason($season);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }

    /** @return bool true si le besoin a changé d'article ou de taille */
    private function arbitrer(DotationBesoin $besoin): bool
    {
        $candidats = $this->substitutsDe($besoin->getStockItem());

        if ($candidats === [] || $this->personneDe($besoin) === null) {
            // Plus aucun article d'écoulement en face : une ligne qui en portait un doit
            // revenir au kit, sinon elle resterait servie par une règle supprimée.
            return $besoin->estServiParEcoulement() ? $this->appliquer($besoin, null) : false;
        }

        $epingle = $besoin->isArticleManuel() ? $besoin->getArticleEcoulement() : null;

        // Épinglé sur l'article du kit : l'admin a tranché, aucun substitut ne le déloge et
        // aucune unité n'est réservée pour lui.
        if ($besoin->isArticleManuel() && $epingle === null) {
            return false;
        }

        $retenu = $epingle !== null
            ? $this->honorerEpinglage($besoin, $epingle, $candidats)
            : $this->premierServable($besoin, $candidats);

        // Un épinglage que le stock ne couvre plus est relâché : mieux vaut une ligne revenue
        // au kit — donc commandée — qu'une réservation qui ment sur ce qu'il reste en armoire.
        if ($retenu === null && $besoin->isArticleManuel()) {
            $besoin->setArticleManuel(false);
        }

        return $this->appliquer($besoin, $retenu);
    }

    /**
     * @param list<StockItem> $candidats
     */
    private function honorerEpinglage(DotationBesoin $besoin, StockItem $epingle, array $candidats): ?StockItem
    {
        foreach ($candidats as $candidat) {
            if ($candidat->getId() === $epingle->getId()) {
                return $this->servable($besoin, $candidat) ? $candidat : null;
            }
        }

        // L'article épinglé n'écoule plus celui du kit : la règle a été retirée entre-temps.
        return null;
    }

    /**
     * @param list<StockItem> $candidats
     */
    private function premierServable(DotationBesoin $besoin, array $candidats): ?StockItem
    {
        foreach ($candidats as $candidat) {
            if ($this->servable($besoin, $candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /** Le stock de ce substitut couvre-t-il ce besoin ? Si oui, les unités sont réservées. */
    private function servable(DotationBesoin $besoin, StockItem $candidat): bool
    {
        $taille = $this->tailleServie($besoin, $candidat);

        // Taille due mais intraduisible dans la grille du substitut : on ne substitue pas.
        if ($taille === null && $candidat->getTypeVetement() !== null) {
            return false;
        }

        $cle = $taille ?? '';
        $restant = $this->poolDe($candidat)[$cle] ?? 0;

        if ($restant < $besoin->getQuantite()) {
            return false;
        }

        $this->pool[$candidat->getId()][$cle] = $restant - $besoin->getQuantite();

        return true;
    }

    /** @return bool true si quelque chose a changé */
    private function appliquer(DotationBesoin $besoin, ?StockItem $retenu): bool
    {
        $taille = $this->tailleServie($besoin, $retenu ?? $besoin->getStockItem());
        $changed = $besoin->getArticleEcoulement()?->getId() !== $retenu?->getId();

        $besoin->setArticleEcoulement($retenu);

        // La taille suit l'article servi : le « 44 » du référentiel devient le « 43-46 » de
        // l'un et le « 43/46 » de l'autre. Une taille fixée à la main, elle, ne bouge pas.
        if (!$besoin->isTailleManuelle() && $besoin->getTaille() !== $taille) {
            $besoin->setTaille($taille);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Taille à servir pour cet article. Une taille fixée à la main par l'admin n'est reprise
     * que si l'article sait la décliner — un « 43-46 » saisi sur l'ERIMA n'a pas de sens sur
     * un carton Nike étiqueté « 44 ».
     */
    private function tailleServie(DotationBesoin $besoin, StockItem $article): ?string
    {
        if ($besoin->isTailleManuelle()) {
            $taille = $besoin->getTaille();

            return $taille !== null && $this->tailles->estAdmise($article, $taille) ? $taille : null;
        }

        $personne = $this->personneDe($besoin);

        return $personne !== null ? $this->resolver->sizeFor($personne, $article) : null;
    }

    /** @return array<string, int> */
    private function poolDe(StockItem $item): array
    {
        return $this->pool[$item->getId()] ??= $this->movementRepository->getStockGroupedByTaille($item);
    }

    /** @return list<StockItem> */
    private function substitutsDe(StockItem $officiel): array
    {
        return $this->substitutsCache[$officiel->getId()] ??= $this->itemRepository->findSubstituts($officiel);
    }

    private function personneDe(DotationBesoin $besoin): Licencie|Dirigeant|null
    {
        return $besoin->getLicencie() ?? $besoin->getDirigeant();
    }
}

<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Entity\Season;
use App\Enum\DotationProvenance;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockMovementRepository;

/**
 * Dit, pour chaque besoin encore à remettre, si l'article est déjà dans l'armoire, en
 * route, ou à commander.
 *
 * Lecture seule : rien n'est écrit, contrairement à {@see DotationEcoulementAllocator}
 * qui, lui, arbitre et enregistre. Les deux répartissent pourtant le même stock, et dans
 * le même ordre — par création du besoin, premier inscrit premier servi. C'est ce qui
 * fait qu'une ligne servie depuis un stock en cours d'écoulement s'affiche toujours
 * « Stock » : l'allocateur ne substitue jamais au-delà de ce qui reste.
 *
 * Répartir plutôt que comparer chaque ligne au stock isolément est le point important :
 * avec une seule paire restante et trois personnes qui l'attendent, un test ligne à ligne
 * annoncerait trois fois « Stock » et enverrait deux dirigeants chercher un carton vide.
 *
 * Les besoins déjà donnés sont hors sujet : leur sortie de stock est faite, elle est déjà
 * déduite du pool.
 */
final class DotationProvenanceResolver
{
    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly CommandeLigneRepository $commandeLigneRepository,
    ) {}

    /**
     * Provenance des besoins à remettre de la saison, indexée par id de besoin. Les lignes
     * déjà remises n'y figurent pas : le template n'a rien à en dire.
     *
     * @return array<int, DotationProvenance>
     */
    public function parBesoin(Season $season): array
    {
        $stock = [];
        $enAttente = $this->commandeLigneRepository->sumPendingByItemTaille();
        $out = [];

        foreach ($this->besoinsAServir($season) as $besoin) {
            $item = $besoin->getArticleServi();
            $taille = $besoin->getTaille() ?? '';
            $quantite = $besoin->getQuantite();

            $stock[$item->getId()] ??= $this->movementRepository->getStockGroupedByTaille($item);

            if (($stock[$item->getId()][$taille] ?? 0) >= $quantite) {
                $stock[$item->getId()][$taille] -= $quantite;
                $out[$besoin->getId()] = DotationProvenance::EN_STOCK;

                continue;
            }

            // Servie à moitié par le stock, à moitié par la commande : ce n'est pas « Stock »,
            // le préparateur ne repartirait pas avec le compte. On ne consomme pas le reliquat
            // non plus — il reste offert à une ligne qu'il couvre entièrement.
            $cle = $item->getId() . '|' . $taille;

            if (($enAttente[$cle] ?? 0) >= $quantite) {
                $enAttente[$cle] -= $quantite;
                $out[$besoin->getId()] = DotationProvenance::COMMANDE;

                continue;
            }

            $out[$besoin->getId()] = DotationProvenance::A_COMMANDER;
        }

        return $out;
    }

    /**
     * Besoins à remettre, dans l'ordre de leur création — le même que celui de
     * l'allocateur d'écoulement, et pour la même raison : deux écrans consécutifs doivent
     * annoncer la même chose.
     *
     * @return list<DotationBesoin>
     */
    private function besoinsAServir(Season $season): array
    {
        $besoins = $this->besoinRepository->findADonnerBySeason($season);
        usort($besoins, static fn (DotationBesoin $a, DotationBesoin $b): int => $a->getId() <=> $b->getId());

        return $besoins;
    }
}

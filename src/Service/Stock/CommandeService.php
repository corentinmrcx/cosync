<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CommandeService
{
    public function __construct(
        private readonly AchatService $achatService,
        private readonly StockService $stockService,
        private readonly CommandeRepository $commandeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Crée un brouillon de commande par fournisseur depuis le « à commander ».
     * Les brouillons existants de la saison sont d'abord purgés : régénérer reflète
     * toujours l'état courant du « à commander » sans créer de doublons. Les commandes
     * déjà passées (commandée / reçue) ne sont jamais touchées.
     *
     * @return Commande[]
     */
    public function genererBons(Season $season): array
    {
        foreach ($this->commandeRepository->findBrouillonsBySeason($season) as $brouillon) {
            $this->em->remove($brouillon);
        }
        $this->em->flush();

        $created = [];

        foreach ($this->achatService->computeACommander($season) as $groupe) {
            $commande = (new Commande())
                ->setSeason($season)
                ->setFournisseur($groupe['fournisseur']);

            foreach ($groupe['lignes'] as $l) {
                $ligne = (new CommandeLigne())
                    ->setStockItem($l['stockItem'])
                    ->setTaille($l['taille'])
                    ->setQuantite($l['aCommander'])
                    ->setPrixUnitaire($l['stockItem']->getPrixAchat());
                $commande->addLigne($ligne);
                $this->em->persist($ligne);
            }

            $this->em->persist($commande);
            $created[] = $commande;
        }

        $this->em->flush();

        return $created;
    }

    /** @throws \DomainException si la commande n'est plus un brouillon */
    public function supprimerBrouillon(Commande $commande): void
    {
        // Une commande passée a généré des mouvements de stock : la supprimer les orphelinerait.
        if ($commande->getStatut() !== CommandeStatut::BROUILLON) {
            throw new \DomainException('Seul un brouillon peut être supprimé.');
        }

        $this->em->remove($commande);
        $this->em->flush();
    }

    public function compterEnAttente(Season $season): int
    {
        $total = 0;
        foreach ($this->commandeRepository->findBySeason($season) as $commande) {
            if ($commande->getStatut()->isEnAttente()) {
                ++$total;
            }
        }

        return $total;
    }

    public function marquerCommandee(Commande $commande, \DateTimeImmutable $date): void
    {
        $commande->setStatut(CommandeStatut::COMMANDEE)->setDateCommande($date);
        $this->em->flush();
    }

    /** Réceptionne une quantité (bornée au restant) → mouvement ENTREE (avec taille) + statut recalculé. */
    public function recevoirLigne(CommandeLigne $ligne, int $qty, ?User $user): void
    {
        $qty = min(max(0, $qty), $ligne->getRestant());
        if ($qty === 0) {
            return;
        }

        $this->stockService->recordMovement(
            $ligne->getStockItem(),
            $qty,
            StockMovementType::ENTREE,
            StockMovementSource::COMMANDE,
            $user,
            'Réception commande #' . $ligne->getCommande()->getId(),
            taille: $ligne->getTaille(),
        );

        $ligne->setQuantiteRecue($ligne->getQuantiteRecue() + $qty);
        $this->recomputeStatut($ligne->getCommande());
        $this->em->flush();
    }

    /**
     * Annule la réception d'une ligne : réverse le stock par un mouvement compensatoire (SORTIE,
     * source COMMANDE), remet la quantité reçue à zéro et recalcule le statut de la commande.
     */
    public function annulerReception(CommandeLigne $ligne, ?User $user): void
    {
        $recu = $ligne->getQuantiteRecue();
        if ($recu <= 0) {
            return;
        }

        $this->stockService->recordMovement(
            $ligne->getStockItem(),
            $recu,
            StockMovementType::SORTIE,
            StockMovementSource::COMMANDE,
            $user,
            'Annulation réception CMD-' . $ligne->getCommande()->getId(),
            taille: $ligne->getTaille(),
        );

        $ligne->setQuantiteRecue(0);
        $this->recomputeStatut($ligne->getCommande());
        $this->em->flush();
    }

    private function recomputeStatut(Commande $commande): void
    {
        $restant = 0;
        $recu = 0;
        foreach ($commande->getLignes() as $ligne) {
            $restant += $ligne->getRestant();
            $recu += $ligne->getQuantiteRecue();
        }

        if ($restant === 0) {
            $commande->setStatut(CommandeStatut::RECUE);
        } elseif ($recu > 0) {
            $commande->setStatut(CommandeStatut::RECUE_PARTIELLE);
        } else {
            // Plus rien de reçu → la commande redevient simplement « commandée ».
            $commande->setStatut(CommandeStatut::COMMANDEE);
        }
    }
}

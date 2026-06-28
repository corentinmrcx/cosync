<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use Doctrine\ORM\EntityManagerInterface;

final class CommandeService
{
    public function __construct(
        private readonly AchatService $achatService,
        private readonly StockService $stockService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Crée un brouillon de commande par fournisseur depuis le « à commander ».
     *
     * @return Commande[]
     */
    public function genererBons(Season $season): array
    {
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

    private function recomputeStatut(Commande $commande): void
    {
        $restant = 0;
        $recu    = 0;
        foreach ($commande->getLignes() as $ligne) {
            $restant += $ligne->getRestant();
            $recu    += $ligne->getQuantiteRecue();
        }

        if ($restant === 0) {
            $commande->setStatut(CommandeStatut::RECUE);
        } elseif ($recu > 0) {
            $commande->setStatut(CommandeStatut::RECUE_PARTIELLE);
        }
    }
}

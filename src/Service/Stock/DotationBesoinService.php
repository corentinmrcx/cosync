<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\DirigeantRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\LicencieRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matérialise et tient à jour les besoins de dotation d'une personne à partir
 * du modèle résolu (DotationResolver). Idempotent : préserve les besoins déjà
 * « donnés », met à jour les « à donner », retire ceux qui ne sont plus dus.
 */
final class DotationBesoinService
{
    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockService $stockService,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function recomputeForLicencie(Licencie $licencie): void
    {
        $this->recompute($licencie, $this->besoinRepository->findForLicencie($licencie));
    }

    public function recomputeForDirigeant(Dirigeant $dirigeant): void
    {
        $this->recompute($dirigeant, $this->besoinRepository->findForDirigeant($dirigeant));
    }

    /** Recalcule pour toute la saison. Retourne le nombre de personnes traitées. */
    public function recomputeAll(Season $season): int
    {
        $count = 0;

        foreach ($this->licencieRepository->findValidatedBySeason($season) as $licencie) {
            $this->recomputeForLicencie($licencie);
            $count++;
        }

        foreach ($this->dirigeantRepository->findBySeason($season) as $dirigeant) {
            if ($dirigeant->isPublicFormComplete()) {
                $this->recomputeForDirigeant($dirigeant);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param DotationBesoin[] $existants
     */
    private function recompute(Licencie|Dirigeant $person, array $existants): void
    {
        $resolved = $this->resolver->resolveDotation($person);

        /** @var array<int, DotationBesoin[]> $existantsParItem */
        $existantsParItem = [];
        foreach ($existants as $besoin) {
            $existantsParItem[$besoin->getStockItem()->getId()][] = $besoin;
        }

        $itemsResolus = [];

        foreach ($resolved as $ligne) {
            $item   = $ligne['stockItem'];
            $itemId = $item->getId();
            $itemsResolus[$itemId] = true;

            $besoinsItem = $existantsParItem[$itemId] ?? [];

            // Cherche un besoin « à donner » à mettre à jour
            $aMettreAJour = null;
            foreach ($besoinsItem as $besoin) {
                if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER) {
                    $aMettreAJour = $besoin;
                    break;
                }
            }

            if ($aMettreAJour !== null) {
                $aMettreAJour
                    ->setQuantite($ligne['quantite'])
                    ->setGroupeChoix($ligne['groupeChoix']);
                // La taille saisie à la main par l'admin prime sur celle déduite du dossier.
                if (!$aMettreAJour->isTailleManuelle()) {
                    $aMettreAJour->setTaille($ligne['taille']);
                }
            } elseif ($besoinsItem === []) {
                // Aucun besoin pour cet article → en créer un
                $besoin = (new DotationBesoin())
                    ->setSeason($person->getSeason())
                    ->setStockItem($item)
                    ->setQuantite($ligne['quantite'])
                    ->setTaille($ligne['taille'])
                    ->setGroupeChoix($ligne['groupeChoix']);

                if ($person instanceof Licencie) {
                    $besoin->setLicencie($person);
                } else {
                    $besoin->setDirigeant($person);
                }

                $this->em->persist($besoin);
            }
            // S'il n'existe que des besoins « donnés » pour cet article → on les laisse (déjà remis).
        }

        // Retire les besoins « à donner » qui ne sont plus dans le modèle résolu
        foreach ($existants as $besoin) {
            if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER
                && !isset($itemsResolus[$besoin->getStockItem()->getId()])) {
                $this->em->remove($besoin);
            }
        }

        $this->em->flush();
    }

    /**
     * Met à jour silencieusement les tailles depuis le dossier en cours pour tous les besoins
     * « à donner » non verrouillés manuellement. Appelé à l'affichage du suivi.
     */
    public function syncTaillesFromDossiers(Season $season): void
    {
        $changed = false;
        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            if ($besoin->getStatut() !== DotationBesoinStatut::A_DONNER) {
                continue;
            }
            if ($besoin->isTailleManuelle()) {
                continue;
            }

            $person   = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $resolved = $person !== null
                ? $this->resolver->sizeFor($person, $besoin->getStockItem()->getTypeVetement())
                : null;

            if ($resolved !== $besoin->getTaille()) {
                $besoin->setTaille($resolved);
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }
    }

    /**
     * Fixe (ou réinitialise) à la main la taille d'un besoin encore « à donner ».
     * Une taille vide repasse le besoin en mode automatique (déduit du dossier au prochain recalcul).
     */
    public function updateTaille(DotationBesoin $besoin, ?string $taille): void
    {
        if ($besoin->getStatut() !== DotationBesoinStatut::A_DONNER) {
            return;
        }

        $taille = trim((string) $taille) ?: null;
        $besoin->setTaille($taille)->setTailleManuelle($taille !== null);
        $this->em->flush();
    }

    /** Marque un besoin comme remis : crée le mouvement de sortie et passe le statut à « donné ». */
    public function markGiven(DotationBesoin $besoin, ?User $user): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            return;
        }

        $note = 'Dotation' . ($besoin->getTaille() !== null ? ' — taille ' . $besoin->getTaille() : '');

        $movement = $this->stockService->recordMovement(
            $besoin->getStockItem(),
            $besoin->getQuantite(),
            StockMovementType::SORTIE,
            StockMovementSource::DOTATION,
            $user,
            $note,
            taille: $besoin->getTaille(),
        );

        $movement->setLicencie($besoin->getLicencie());
        $movement->setDirigeant($besoin->getDirigeant());

        $besoin->setStatut(DotationBesoinStatut::DONNE)->setMouvementSortie($movement);
        $this->em->flush();
    }

    /** Annule une remise : repasse le besoin à « à donner » et supprime le mouvement de sortie. */
    public function cancelGiven(DotationBesoin $besoin): void
    {
        if ($besoin->getStatut() !== DotationBesoinStatut::DONNE) {
            return;
        }

        $movement = $besoin->getMouvementSortie();
        $besoin->setStatut(DotationBesoinStatut::A_DONNER)->setMouvementSortie(null);

        if ($movement !== null) {
            $this->em->remove($movement);
        }

        $this->em->flush();
    }
}

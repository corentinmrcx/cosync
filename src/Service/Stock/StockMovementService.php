<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\ManualMovementData;
use App\Entity\Licencie;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\LicencieRepository;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture des mouvements de stock : entrées, sorties, rebuts, et les gardes qui les
 * encadrent. La lecture est dans StockReportService.
 */
final class StockMovementService
{
    public function __construct(
        private readonly StockMovementRepository $movementRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrentStock(StockItem $item): int
    {
        return $this->movementRepository->getCurrentStock($item);
    }

    public function recordMovement(
        StockItem $item,
        int $quantite,
        StockMovementType $type,
        StockMovementSource $source,
        ?User $createdBy,
        ?string $note,
        ?string $sumupTransactionId = null,
        ?string $taille = null,
        bool $preventNegative = false,
    ): StockMovement {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }
        if ($type === StockMovementType::REBUT && empty(trim($note ?? ''))) {
            throw new \InvalidArgumentException('Une justification est obligatoire pour un rebut.');
        }

        if ($preventNegative && in_array($type, [StockMovementType::SORTIE, StockMovementType::REBUT], true)) {
            $tailleNorm = trim((string) $taille) ?: null;
            $disponible = $this->movementRepository->getCurrentStockByTaille($item, $tailleNorm);
            if ($quantite > $disponible) {
                throw new \InvalidArgumentException(sprintf('Stock insuffisant : %d en stock%s, impossible d\'en sortir %d.', $disponible, $tailleNorm !== null ? ' (taille ' . $tailleNorm . ')' : '', $quantite));
            }
        }

        $movement = new StockMovement();
        $movement->setItem($item);
        $movement->setQuantite($quantite);
        $movement->setType($type);
        $movement->setSource($source);
        $movement->setNote($note ?: null);
        $movement->setCreatedBy($createdBy);
        $movement->setSumupTransactionId($sumupTransactionId);
        $movement->setTaille(trim((string) $taille) ?: null);

        $this->em->persist($movement);
        $this->em->flush();

        return $movement;
    }

    /**
     * Mouvement saisi à la main depuis la modale de gestion : applique le mapping action → type/source,
     * les règles métier (dotation = licencié au paiement confirmé) et la garde anti-négatif.
     *
     * @throws \InvalidArgumentException si l'action est invalide, le licencié manquant/non validé,
     *                                   ou le stock insuffisant
     */
    public function recordManualMovement(StockItem $item, ManualMovementData $data, ?User $createdBy): StockMovement
    {
        $licencie = $data->action->exigeUnLicencie()
            ? $this->resolveValidatedLicencie($data->licencieUuid)
            : null;

        $movement = $this->recordMovement(
            $item,
            $data->quantite,
            $data->action->type(),
            $data->action->source(),
            $createdBy,
            $data->note,
            taille: $data->taille,
            preventNegative: $data->action->interditLeDecouvert(),
        );

        if ($licencie !== null) {
            $movement->setLicencie($licencie);
            $this->em->flush();
        }

        return $movement;
    }

    /**
     * Supprime un mouvement saisi à la main. Le stock étant dérivé des mouvements, la suppression
     * recalcule automatiquement le stock. Interdit sur les mouvements dotation/commande/SumUp :
     * ceux-ci se corrigent via leur écran dédié pour ne pas désynchroniser besoin ou commande.
     *
     * @throws \InvalidArgumentException si le mouvement n'est pas d'origine manuelle
     */
    public function deleteManualMovement(StockMovement $movement): void
    {
        if ($movement->getSource() !== StockMovementSource::MANUEL) {
            throw new \InvalidArgumentException('Seuls les mouvements manuels peuvent être supprimés ici. Corrigez une dotation ou une réception depuis son écran dédié.');
        }

        $this->em->remove($movement);
        $this->em->flush();
    }

    private function resolveValidatedLicencie(?string $uuid): Licencie
    {
        if ($uuid === null || $uuid === '') {
            throw new \InvalidArgumentException('Veuillez sélectionner un licencié pour une dotation.');
        }

        $licencie = $this->licencieRepository->findOneBy(['uuid' => $uuid]);
        if ($licencie === null) {
            throw new \InvalidArgumentException('Licencié introuvable.');
        }

        $dossier = $licencie->getDossierClub();
        if ($dossier === null || $dossier->getStatus() !== LicenceStatus::VALIDATED) {
            throw new \InvalidArgumentException(sprintf('La dotation ne peut être enregistrée qu\'après confirmation du paiement de %s.', $licencie->getNomPrenom()));
        }

        return $licencie;
    }
}

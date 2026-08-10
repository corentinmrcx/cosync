<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Repository\StockMovementRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie d'un article de stock.
 */
final class StockItemService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementRepository $movementRepository,
    ) {}

    public function creer(StockItem $item): void
    {
        $this->em->persist($item);
        $this->em->flush();
    }

    public function enregistrer(StockItem $item): void
    {
        $this->em->flush();
    }

    /**
     * Supprimer un article dont on a déjà tracé des mouvements effacerait l'historique :
     * on l'archive à la place. L'archivage est donc le cas normal, pas l'exception.
     *
     * @return bool true si l'article a été archivé, false s'il a été réellement supprimé
     *
     * @throws \DomainException si une dotation ou une commande le référence encore
     */
    public function supprimerOuArchiver(StockItem $item): bool
    {
        if ($this->movementRepository->count(['item' => $item]) > 0) {
            $item->setActif(false);
            $this->em->flush();

            return true;
        }

        try {
            $this->em->remove($item);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw new \DomainException(sprintf('Impossible de supprimer "%s" : il est référencé par une dotation ou une commande.', $item->getNom()));
        }

        return false;
    }

    /**
     * Applique les champs conditionnels au type d'article (équipement vs épicerie) sur un StockItem.
     * Centralise la règle : un vêtement n'a pas de taille figée (déclinaisons de stock), l'épicerie
     * porte sa contenance dans « taille ».
     */
    public function applyEditableFields(
        StockItem $item,
        ?StockItemKind $kind,
        ?string $marque,
        ?string $couleur,
        ?string $taille,
        ?StockItemVetementType $typeVetement,
    ): void {
        $item->setKind($kind);
        $item->setMarque($marque ?: null);

        if ($kind === StockItemKind::EQUIPEMENT) {
            $item->setTaille(null);
            $item->setCouleur($couleur ?: null);
            $item->setTypeVetement($typeVetement);
        } else {
            $item->setTaille($taille ?: null);
            $item->setCouleur(null);
            $item->setTypeVetement(null);
        }
    }

    public function restaurer(StockItem $item): void
    {
        $item->setActif(true);
        $this->em->flush();
    }
}

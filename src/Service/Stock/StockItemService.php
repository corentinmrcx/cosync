<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Stock\SuppressionArticle;
use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\DotationModeleLigneRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
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
        private readonly StockTailleNoteRepository $noteRepository,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationModeleLigneRepository $modeleLigneRepository,
        private readonly CommandeLigneRepository $commandeLigneRepository,
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
     * Dit d'avance ce qu'il adviendra de l'article, pour que l'écran de confirmation
     * l'annonce au lieu de le constater.
     *
     * Un article qui ne porte plus rien et n'a été manipulé qu'à la main est une erreur de
     * saisie : il part pour de bon, avec ses mouvements — les garder sans leur article ne
     * dirait plus ce qui est entré ni ce qui est sorti. Dès qu'une dotation, une commande
     * ou une caisse l'a touché, la trace n'est plus une erreur mais une histoire : on
     * archive. Idem tant qu'il reste des unités : l'article existe encore physiquement.
     */
    public function analyserSuppression(StockItem $item): SuppressionArticle
    {
        // Taille par taille : un +5 en L compensé par un −5 en M donnerait un total nul
        // alors que le placard n'est pas vide.
        $parTaille = $this->movementRepository->getStockGroupedByTaille($item);
        $nonSoldees = array_filter($parTaille, static fn (int $quantite): bool => $quantite !== 0);
        if ($nonSoldees !== []) {
            $restant = array_sum($nonSoldees);

            return SuppressionArticle::aArchiver($restant > 0
                ? sprintf('il reste %d %s en stock.', $restant, $restant > 1 ? 'unités' : 'unité')
                : 'son stock par taille n\'est pas soldé.');
        }

        $mouvements = $this->movementRepository->count(['item' => $item]);
        $manuels = $this->movementRepository->count(['item' => $item, 'source' => StockMovementSource::MANUEL]);
        if ($mouvements !== $manuels) {
            return SuppressionArticle::aArchiver('des dotations, des réceptions de commande ou des ventes y sont rattachées : leur trace serait perdue.');
        }

        foreach ($this->referencesBloquantes($item) as $motif => $compte) {
            if ($compte > 0) {
                return SuppressionArticle::aArchiver($motif);
            }
        }

        return SuppressionArticle::supprimable($mouvements);
    }

    /** Sort l'article des listes sans rien perdre. Réversible par `restaurer()`. */
    public function archiver(StockItem $item): void
    {
        $item->setActif(false);
        $this->em->flush();
    }

    /**
     * Supprime l'article si l'analyse l'autorise, l'archive sinon. L'archivage est le cas
     * normal : un article sorti du catalogue reste dans l'historique du club.
     *
     * @return bool true si l'article a été archivé, false s'il a été réellement supprimé
     *
     * @throws \DomainException si une dotation ou une commande le référence encore
     */
    public function supprimerOuArchiver(StockItem $item): bool
    {
        if (!$this->analyserSuppression($item)->supprimable) {
            $this->archiver($item);

            return true;
        }

        // Les mouvements et les notes de taille n'existent que par leur article : ils
        // partent avec lui, sans quoi la suppression bute sur leur clé étrangère.
        foreach ($this->movementRepository->findByItem($item) as $movement) {
            $this->em->remove($movement);
        }
        foreach ($this->noteRepository->findByItem($item) as $note) {
            $this->em->remove($note);
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
     * Références qui interdisent la suppression, motif d'archivage en clé.
     *
     * @return array<string, int>
     */
    private function referencesBloquantes(StockItem $item): array
    {
        return [
            'il figure dans un kit de dotation.' => $this->modeleLigneRepository->count(['stockItem' => $item]),
            'il est attendu dans une dotation à remettre.' => $this->besoinRepository->count(['stockItem' => $item]),
            'il figure sur un bon de commande.' => $this->commandeLigneRepository->count(['stockItem' => $item]),
        ];
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

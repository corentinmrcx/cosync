<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Entity\StockTailleNote;
use App\Entity\User;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture des remarques portées sur le stock d'un article : une par taille, en plus de
 * celle de l'article lui-même (portée par StockItem::note, saisie dans son formulaire).
 */
final class StockNoteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockTailleNoteRepository $noteRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockTailleResolver $taillesResolver,
    ) {}

    /** Note de l'article, toutes tailles confondues. Vidée, elle est effacée. */
    public function enregistrerNoteArticle(StockItem $item, ?string $note): void
    {
        $item->setNote(trim((string) $note) ?: null);
        $this->em->flush();
    }

    /**
     * Enregistre la note d'une déclinaison. Une note vidée est supprimée : garder une ligne
     * vide ferait afficher un pictogramme de remarque qui ne dit rien.
     *
     * @throws \InvalidArgumentException si la taille n'appartient pas à l'article
     */
    public function enregistrerNoteTaille(StockItem $item, string $taille, ?string $note, ?User $auteur): void
    {
        $taille = trim($taille);
        $note = trim((string) $note);

        $this->assertTailleAdmise($item, $taille);

        $ligne = $this->noteRepository->findOneForTaille($item, $taille);

        if ($note === '') {
            if ($ligne !== null) {
                $this->em->remove($ligne);
                $this->em->flush();
            }

            return;
        }

        if ($ligne === null) {
            $ligne = (new StockTailleNote())->setItem($item)->setTaille($taille);
            $this->em->persist($ligne);
        }

        $ligne->setNote($note)->setUpdatedBy($auteur);
        $this->em->flush();
    }

    /**
     * Mêmes tailles que la modale de mouvement : celles du référentiel de l'article, plus
     * celles déjà présentes en stock — un article dont le type a changé garde des
     * déclinaisons hors référentiel, qui ont bien le droit à une remarque.
     *
     * @throws \InvalidArgumentException si la taille n'appartient pas à l'article
     */
    private function assertTailleAdmise(StockItem $item, string $taille): void
    {
        if ($taille === '') {
            throw new \InvalidArgumentException('Aucune taille indiquée pour cette note.');
        }

        $dejaUtilisees = array_map('strval', array_keys($this->movementRepository->getStockGroupedByTaille($item)));
        if (!$this->taillesResolver->estAdmise($item, $taille, $dejaUtilisees)) {
            throw new \InvalidArgumentException(sprintf('La taille "%s" ne correspond pas à l\'article "%s".', $taille, $item->getNom()));
        }
    }
}

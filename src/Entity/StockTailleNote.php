<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockTailleNoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note libre attachée à une déclinaison de taille d'un article : « le 128 taille petit »,
 * « les L sont au fond du placard ». La remarque de l'admin ne vaut souvent que pour une
 * taille — la porter sur l'article la ferait lire par erreur sur toutes les autres.
 *
 * Un couple (article, taille) porte au plus une note : une note vidée est supprimée.
 */
#[ORM\Entity(repositoryClass: StockTailleNoteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_stock_taille_note', columns: ['item_id', 'taille'])]
class StockTailleNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StockItem $item;

    #[ORM\Column(length: 20)]
    private string $taille;

    #[ORM\Column(type: 'text')]
    private string $note;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItem(): StockItem
    {
        return $this->item;
    }

    public function setItem(StockItem $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getTaille(): string
    {
        return $this->taille;
    }

    public function setTaille(string $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): static
    {
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}

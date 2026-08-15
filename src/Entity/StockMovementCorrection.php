<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockMovementCorrectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une correction apportée à un mouvement de stock saisi à la main.
 *
 * Corriger une erreur de frappe en supprimant puis en resaisissant le mouvement efface la
 * question « pourquoi ce chiffre a-t-il changé ? ». Le mouvement porte donc la valeur juste,
 * et cette table — append-only — garde ce qu'il valait avant, quand, par qui, et sur quelle
 * justification.
 */
#[ORM\Entity(repositoryClass: StockMovementCorrectionRepository::class)]
class StockMovementCorrection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'corrections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StockMovement $movement;

    #[ORM\Column]
    private int $quantiteAvant;

    #[ORM\Column]
    private int $quantiteApres;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleAvant = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleApres = null;

    /** Justification obligatoire : une correction sans motif ne vaut pas mieux qu'un effacement. */
    #[ORM\Column(type: 'text')]
    private string $motif;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $correctedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMovement(): StockMovement
    {
        return $this->movement;
    }

    public function setMovement(StockMovement $movement): static
    {
        $this->movement = $movement;

        return $this;
    }

    public function getQuantiteAvant(): int
    {
        return $this->quantiteAvant;
    }

    public function setQuantiteAvant(int $quantiteAvant): static
    {
        $this->quantiteAvant = $quantiteAvant;

        return $this;
    }

    public function getQuantiteApres(): int
    {
        return $this->quantiteApres;
    }

    public function setQuantiteApres(int $quantiteApres): static
    {
        $this->quantiteApres = $quantiteApres;

        return $this;
    }

    public function getTailleAvant(): ?string
    {
        return $this->tailleAvant;
    }

    public function setTailleAvant(?string $tailleAvant): static
    {
        $this->tailleAvant = $tailleAvant;

        return $this;
    }

    public function getTailleApres(): ?string
    {
        return $this->tailleApres;
    }

    public function setTailleApres(?string $tailleApres): static
    {
        $this->tailleApres = $tailleApres;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getCorrectedBy(): ?User
    {
        return $this->correctedBy;
    }

    public function setCorrectedBy(?User $correctedBy): static
    {
        $this->correctedBy = $correctedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Vrai si la correction a changé la taille — l'affichage ne mentionne que ce qui a bougé. */
    public function tailleChangee(): bool
    {
        return $this->tailleAvant !== $this->tailleApres;
    }

    public function quantiteChangee(): bool
    {
        return $this->quantiteAvant !== $this->quantiteApres;
    }
}

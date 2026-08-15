<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\StockMovementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
class StockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private StockItem $item;

    #[ORM\Column]
    private int $quantite;

    #[ORM\Column(enumType: StockMovementType::class)]
    private StockMovementType $type;

    /** Taille concernée (null pour épicerie / objet sans taille) — stock suivi par (article, taille) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taille = null;

    /** Renseigné pour les sorties liées à un joueur */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid')]
    private ?Licencie $licencie = null;

    /** Renseigné pour les sorties liées à un dirigeant */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid')]
    private ?Dirigeant $dirigeant = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(enumType: StockMovementSource::class)]
    private StockMovementSource $source = StockMovementSource::MANUEL;

    /** Identifiant de la transaction SumUp — Phase 2 */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sumupTransactionId = null;

    /** Nullable pour les mouvements automatiques SumUp (Phase 2) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Corrections successives apportées à ce mouvement, de la plus ancienne à la plus
     * récente. Le mouvement porte la valeur juste ; celles-ci disent d'où elle vient.
     *
     * @var Collection<int, StockMovementCorrection>
     */
    #[ORM\OneToMany(mappedBy: 'movement', targetEntity: StockMovementCorrection::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $corrections;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->source = StockMovementSource::MANUEL;
        $this->corrections = new ArrayCollection();
    }

    /** @return Collection<int, StockMovementCorrection> */
    public function getCorrections(): Collection
    {
        return $this->corrections;
    }

    public function estCorrige(): bool
    {
        return !$this->corrections->isEmpty();
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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getType(): StockMovementType
    {
        return $this->type;
    }

    public function setType(StockMovementType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTaille(): ?string
    {
        return $this->taille;
    }

    public function setTaille(?string $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function getLicencie(): ?Licencie
    {
        return $this->licencie;
    }

    public function setLicencie(?Licencie $licencie): static
    {
        $this->licencie = $licencie;

        return $this;
    }

    public function getDirigeant(): ?Dirigeant
    {
        return $this->dirigeant;
    }

    public function setDirigeant(?Dirigeant $dirigeant): static
    {
        $this->dirigeant = $dirigeant;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getSource(): StockMovementSource
    {
        return $this->source;
    }

    public function setSource(StockMovementSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSumupTransactionId(): ?string
    {
        return $this->sumupTransactionId;
    }

    public function setSumupTransactionId(?string $sumupTransactionId): static
    {
        $this->sumupTransactionId = $sumupTransactionId;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

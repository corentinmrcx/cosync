<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\CleMouvementType;
use App\Repository\CleMouvementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mouvement de clés du club house. Table d'événements append-only : la détention
 * courante est dérivée de l'historique, jamais stockée. Une erreur de saisie se
 * corrige par un mouvement compensatoire, jamais par une suppression.
 */
#[ORM\Entity(repositoryClass: CleMouvementRepository::class)]
class CleMouvement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** Pas de onDelete : l'historique protège contre la suppression d'un détenteur */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'uuid')]
    private Dirigeant $dirigeant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\Column(enumType: CleMouvementType::class)]
    private CleMouvementType $type;

    #[ORM\Column]
    private int $quantite;

    /** Jour du mouvement tel que saisi par l'admin — createdAt reste la trace d'audit */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateMouvement;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dateMouvement = new \DateTimeImmutable('today');
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDirigeant(): Dirigeant
    {
        return $this->dirigeant;
    }

    public function setDirigeant(Dirigeant $dirigeant): static
    {
        $this->dirigeant = $dirigeant;

        return $this;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function setSeason(Season $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getType(): CleMouvementType
    {
        return $this->type;
    }

    public function setType(CleMouvementType $type): static
    {
        $this->type = $type;

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

    public function getDateMouvement(): \DateTimeImmutable
    {
        return $this->dateMouvement;
    }

    public function setDateMouvement(\DateTimeImmutable $dateMouvement): static
    {
        $this->dateMouvement = $dateMouvement;

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

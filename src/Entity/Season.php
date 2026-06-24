<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeasonRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeasonRepository::class)]
class Season
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 20, unique: true)]
    private string $label;

    /** @var array<string, int> ex: {"jeunes": 85, "seniors": 120} */
    #[ORM\Column(type: 'json')]
    private array $baseCosts = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reglementText = null;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getBaseCosts(): array
    {
        return $this->baseCosts;
    }

    public function setBaseCosts(array $baseCosts): static
    {
        $this->baseCosts = $baseCosts;
        return $this;
    }

    public function getReglementText(): ?string
    {
        return $this->reglementText;
    }

    public function setReglementText(?string $reglementText): static
    {
        $this->reglementText = $reglementText;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

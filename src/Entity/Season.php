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

    /** Cotisation appliquée par défaut (€), pour un licencié sans équipe ou une équipe sans cotisation définie. */
    #[ORM\Column(options: ['default' => 0])]
    private int $cotisationDefaut = 0;

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

    public function getCotisationDefaut(): int
    {
        return $this->cotisationDefaut;
    }

    public function setCotisationDefaut(int $cotisationDefaut): static
    {
        $this->cotisationDefaut = $cotisationDefaut;
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

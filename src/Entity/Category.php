<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** ex: "U6", "U11", "SENIOR" */
    #[ORM\Column(length: 10, unique: true)]
    private string $code;

    #[ORM\Column(length: 50)]
    private string $label;

    /** true pour U6 à U13 — conditionne l'affichage des autorisations transport */
    #[ORM\Column]
    private bool $isEcoleFoot;

    #[ORM\Column(nullable: true)]
    private ?int $minYear = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxYear = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function isEcoleFoot(): bool
    {
        return $this->isEcoleFoot;
    }

    /** true pour toutes les catégories jeunes (U6 à U19) — conditionne le message parent/adulte */
    public function isJeune(): bool
    {
        return str_starts_with($this->code, 'U');
    }

    public function setIsEcoleFoot(bool $isEcoleFoot): static
    {
        $this->isEcoleFoot = $isEcoleFoot;

        return $this;
    }

    public function getMinYear(): ?int
    {
        return $this->minYear;
    }

    public function setMinYear(?int $minYear): static
    {
        $this->minYear = $minYear;

        return $this;
    }

    public function getMaxYear(): ?int
    {
        return $this->maxYear;
    }

    public function setMaxYear(?int $maxYear): static
    {
        $this->maxYear = $maxYear;

        return $this;
    }
}

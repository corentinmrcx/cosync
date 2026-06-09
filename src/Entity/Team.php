<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** ex: "U15 A", "Séniors 1", "Loisirs" */
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /**
     * Catégorie FFF par défaut pour cette équipe.
     * Utilisé pour l'auto-assignation à l'import XLSX.
     * Null = équipe spéciale (loisirs, dirigeants…) sans auto-assignation.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $defaultCategory = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getDefaultCategory(): ?Category
    {
        return $this->defaultCategory;
    }

    public function setDefaultCategory(?Category $defaultCategory): static
    {
        $this->defaultCategory = $defaultCategory;
        return $this;
    }
}

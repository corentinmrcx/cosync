<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockItemRepository::class)]
class StockItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 150)]
    private string $nom;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $couleur = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $refCatalogue = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lienAchat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    public function getId(): int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;
        return $this;
    }

    public function getRefCatalogue(): ?string
    {
        return $this->refCatalogue;
    }

    public function setRefCatalogue(?string $refCatalogue): static
    {
        $this->refCatalogue = $refCatalogue;
        return $this;
    }

    public function getLienAchat(): ?string
    {
        return $this->lienAchat;
    }

    public function setLienAchat(?string $lienAchat): static
    {
        $this->lienAchat = $lienAchat;
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
}

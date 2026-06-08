<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockItemVetementType;
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

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $marque = null;

    /** Taille ou contenance : M, L, XL, 33cl, 50cl, EU42… */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taille = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $couleur = null;

    /** Lien avec DossierClub pour les dotations automatiques */
    #[ORM\Column(nullable: true, enumType: StockItemVetementType::class)]
    private ?StockItemVetementType $typeVetement = null;

    /** Prix d'achat unitaire — pour le suivi budgétaire */
    #[ORM\Column(nullable: true)]
    private ?float $prixAchat = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $refCatalogue = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lienAchat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StockCategory $category = null;

    /** Seuil d'alerte stock bas — null = pas d'alerte */
    #[ORM\Column(nullable: true)]
    private ?int $alertSeuil = null;

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

    public function getMarque(): ?string
    {
        return $this->marque;
    }

    public function setMarque(?string $marque): static
    {
        $this->marque = $marque;
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

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;
        return $this;
    }

    public function getTypeVetement(): ?StockItemVetementType
    {
        return $this->typeVetement;
    }

    public function setTypeVetement(?StockItemVetementType $typeVetement): static
    {
        $this->typeVetement = $typeVetement;
        return $this;
    }

    public function getPrixAchat(): ?float
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?float $prixAchat): static
    {
        $this->prixAchat = $prixAchat;
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

    public function getCategory(): ?StockCategory
    {
        return $this->category;
    }

    public function setCategory(?StockCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getAlertSeuil(): ?int
    {
        return $this->alertSeuil;
    }

    public function setAlertSeuil(?int $alertSeuil): static
    {
        $this->alertSeuil = $alertSeuil;
        return $this;
    }
}

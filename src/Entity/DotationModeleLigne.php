<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DotationModeleLigneRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne d'un modèle de dotation : un article (fiche « garment ») + quantité.
 * La taille n'est PAS stockée ici — elle est déduite de la personne au moment de la résolution.
 */
#[ORM\Entity(repositoryClass: DotationModeleLigneRepository::class)]
class DotationModeleLigne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private DotationModele $modele;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private StockItem $stockItem;

    #[ORM\Column]
    private int $quantite = 1;

    #[ORM\Column]
    private bool $obligatoire = true;

    /** Lignes partageant un même groupe = « 1 parmi N » (consommé en livraison C) */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $groupeChoix = null;

    public function getId(): int { return $this->id; }

    public function getModele(): DotationModele { return $this->modele; }
    public function setModele(DotationModele $modele): static { $this->modele = $modele; return $this; }

    public function getStockItem(): StockItem { return $this->stockItem; }
    public function setStockItem(StockItem $stockItem): static { $this->stockItem = $stockItem; return $this; }

    public function getQuantite(): int { return $this->quantite; }
    public function setQuantite(int $quantite): static { $this->quantite = $quantite; return $this; }

    public function isObligatoire(): bool { return $this->obligatoire; }
    public function setObligatoire(bool $obligatoire): static { $this->obligatoire = $obligatoire; return $this; }

    public function getGroupeChoix(): ?string { return $this->groupeChoix; }
    public function setGroupeChoix(?string $groupeChoix): static { $this->groupeChoix = $groupeChoix; return $this; }
}

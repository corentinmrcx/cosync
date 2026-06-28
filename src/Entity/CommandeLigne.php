<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommandeLigneRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une commande : un article + taille + quantité, avec suivi de la quantité reçue.
 */
#[ORM\Entity(repositoryClass: CommandeLigneRepository::class)]
class CommandeLigne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private Commande $commande;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private StockItem $stockItem;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taille = null;

    #[ORM\Column]
    private int $quantite;

    #[ORM\Column]
    private int $quantiteRecue = 0;

    /** Snapshot du prix d'achat à la commande (pour le module Finance). */
    #[ORM\Column(nullable: true)]
    private ?float $prixUnitaire = null;

    public function getId(): int { return $this->id; }

    public function getCommande(): Commande { return $this->commande; }
    public function setCommande(Commande $commande): static { $this->commande = $commande; return $this; }

    public function getStockItem(): StockItem { return $this->stockItem; }
    public function setStockItem(StockItem $stockItem): static { $this->stockItem = $stockItem; return $this; }

    public function getTaille(): ?string { return $this->taille; }
    public function setTaille(?string $taille): static { $this->taille = $taille; return $this; }

    public function getQuantite(): int { return $this->quantite; }
    public function setQuantite(int $quantite): static { $this->quantite = $quantite; return $this; }

    public function getQuantiteRecue(): int { return $this->quantiteRecue; }
    public function setQuantiteRecue(int $quantiteRecue): static { $this->quantiteRecue = $quantiteRecue; return $this; }

    public function getPrixUnitaire(): ?float { return $this->prixUnitaire; }
    public function setPrixUnitaire(?float $prixUnitaire): static { $this->prixUnitaire = $prixUnitaire; return $this; }

    public function getRestant(): int
    {
        return max(0, $this->quantite - $this->quantiteRecue);
    }
}

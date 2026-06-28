<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\DotationBesoinStatut;
use App\Repository\DotationBesoinRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Besoin de dotation matérialisé pour une personne (licencié ou dirigeant) :
 * un article attendu, à une taille, avec un statut « à donner » / « donné ».
 * Généré à partir du modèle résolu (DotationResolver).
 */
#[ORM\Entity(repositoryClass: DotationBesoinRepository::class)]
class DotationBesoin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid', onDelete: 'CASCADE')]
    private ?Licencie $licencie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid', onDelete: 'CASCADE')]
    private ?Dirigeant $dirigeant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private StockItem $stockItem;

    #[ORM\Column]
    private int $quantite = 1;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taille = null;

    #[ORM\Column(length: 20, enumType: DotationBesoinStatut::class)]
    private DotationBesoinStatut $statut = DotationBesoinStatut::A_DONNER;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $groupeChoix = null;

    /** Mouvement de sortie créé lors de la remise. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StockMovement $mouvementSortie = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getSeason(): Season { return $this->season; }
    public function setSeason(Season $season): static { $this->season = $season; return $this; }

    public function getLicencie(): ?Licencie { return $this->licencie; }
    public function setLicencie(?Licencie $licencie): static { $this->licencie = $licencie; return $this; }

    public function getDirigeant(): ?Dirigeant { return $this->dirigeant; }
    public function setDirigeant(?Dirigeant $dirigeant): static { $this->dirigeant = $dirigeant; return $this; }

    public function getStockItem(): StockItem { return $this->stockItem; }
    public function setStockItem(StockItem $stockItem): static { $this->stockItem = $stockItem; return $this; }

    public function getQuantite(): int { return $this->quantite; }
    public function setQuantite(int $quantite): static { $this->quantite = $quantite; return $this; }

    public function getTaille(): ?string { return $this->taille; }
    public function setTaille(?string $taille): static { $this->taille = $taille; return $this; }

    public function getStatut(): DotationBesoinStatut { return $this->statut; }
    public function setStatut(DotationBesoinStatut $statut): static { $this->statut = $statut; return $this; }

    public function getGroupeChoix(): ?string { return $this->groupeChoix; }
    public function setGroupeChoix(?string $groupeChoix): static { $this->groupeChoix = $groupeChoix; return $this; }

    public function getMouvementSortie(): ?StockMovement { return $this->mouvementSortie; }
    public function setMouvementSortie(?StockMovement $mouvementSortie): static { $this->mouvementSortie = $mouvementSortie; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /* — Helpers d'affichage (dérivés de la personne) — */

    public function getNomPrenom(): string
    {
        return $this->licencie?->getNomPrenom() ?? $this->dirigeant?->getNomPrenom() ?? '—';
    }

    public function getRoleLabel(): string
    {
        return $this->licencie !== null ? 'Licencié' : 'Dirigeant';
    }

    public function getTeamName(): ?string
    {
        return $this->licencie?->getTeam()?->getName() ?? $this->dirigeant?->getTeam()?->getName();
    }
}

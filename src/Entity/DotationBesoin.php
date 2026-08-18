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

    /**
     * Article réellement servi quand il diffère de celui du kit : le stock de l'ancien
     * fournisseur qu'on écoule avant de commander du neuf. Null dans le cas normal.
     *
     * Volontairement à côté de `stockItem` plutôt qu'à sa place : le besoin continue de dire
     * ce que le kit prévoit — c'est cela que le recalcul réaligne et que l'emplacement
     * identifie — pendant que cette colonne dit ce qu'on prend dans l'armoire.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StockItem $articleEcoulement = null;

    /** Vrai si l'article servi a été fixé à la main par l'admin → l'écoulement ne l'arbitre plus. */
    #[ORM\Column(options: ['default' => false])]
    private bool $articleManuel = false;

    #[ORM\Column]
    private int $quantite = 1;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taille = null;

    /** Vrai si la taille a été fixée à la main par l'admin → le recalcul ne l'écrase plus. */
    #[ORM\Column]
    private bool $tailleManuelle = false;

    #[ORM\Column(length: 20, enumType: DotationBesoinStatut::class)]
    private DotationBesoinStatut $statut = DotationBesoinStatut::A_DONNER;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $groupeChoix = null;

    /** Texte à floquer, figé à la résolution. Null si l'article n'est pas personnalisé. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $personnalisation = null;

    /**
     * Vrai si le texte a été saisi à la main par l'admin → le recalcul ne l'écrase plus avec
     * celui du dossier. Même verrou que `tailleManuelle`, et pour la même raison : un licencié
     * qui n'a pas pu répondre au formulaire n'a aucun texte à propager, et sans ce drapeau
     * celui de l'admin disparaissait au premier « Recalculer les besoins ».
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $personnalisationManuelle = false;

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

    public function getId(): int
    {
        return $this->id;
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

    public function getLicencie(): ?Licencie
    {
        return $this->licencie;
    }

    public function setLicencie(?Licencie $licencie): static
    {
        $this->licencie = $licencie;

        return $this;
    }

    public function getDirigeant(): ?Dirigeant
    {
        return $this->dirigeant;
    }

    public function setDirigeant(?Dirigeant $dirigeant): static
    {
        $this->dirigeant = $dirigeant;

        return $this;
    }

    public function getStockItem(): StockItem
    {
        return $this->stockItem;
    }

    public function setStockItem(StockItem $stockItem): static
    {
        $this->stockItem = $stockItem;

        return $this;
    }

    public function getArticleEcoulement(): ?StockItem
    {
        return $this->articleEcoulement;
    }

    public function setArticleEcoulement(?StockItem $articleEcoulement): static
    {
        $this->articleEcoulement = $articleEcoulement;

        return $this;
    }

    public function isArticleManuel(): bool
    {
        return $this->articleManuel;
    }

    public function setArticleManuel(bool $articleManuel): static
    {
        $this->articleManuel = $articleManuel;

        return $this;
    }

    /**
     * L'article à sortir du stock, à déduire des achats et à afficher au suivi : celui du kit,
     * sauf quand un article d'écoulement le remplace. Point de lecture unique — lire
     * `getStockItem()` en aval ferait commander du neuf alors que l'ancien stock est servi.
     */
    public function getArticleServi(): StockItem
    {
        return $this->articleEcoulement ?? $this->stockItem;
    }

    /** Vrai quand cette ligne est servie depuis un stock en cours d'écoulement. */
    public function estServiParEcoulement(): bool
    {
        return $this->articleEcoulement !== null;
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

    public function getTaille(): ?string
    {
        return $this->taille;
    }

    public function setTaille(?string $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function isTailleManuelle(): bool
    {
        return $this->tailleManuelle;
    }

    public function setTailleManuelle(bool $tailleManuelle): static
    {
        $this->tailleManuelle = $tailleManuelle;

        return $this;
    }

    public function getStatut(): DotationBesoinStatut
    {
        return $this->statut;
    }

    public function setStatut(DotationBesoinStatut $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getGroupeChoix(): ?string
    {
        return $this->groupeChoix;
    }

    public function setGroupeChoix(?string $groupeChoix): static
    {
        $this->groupeChoix = $groupeChoix;

        return $this;
    }

    public function getPersonnalisation(): ?string
    {
        return $this->personnalisation;
    }

    public function setPersonnalisation(?string $personnalisation): static
    {
        $this->personnalisation = $personnalisation;

        return $this;
    }

    public function isPersonnalisationManuelle(): bool
    {
        return $this->personnalisationManuelle;
    }

    public function setPersonnalisationManuelle(bool $personnalisationManuelle): static
    {
        $this->personnalisationManuelle = $personnalisationManuelle;

        return $this;
    }

    public function getMouvementSortie(): ?StockMovement
    {
        return $this->mouvementSortie;
    }

    public function setMouvementSortie(?StockMovement $mouvementSortie): static
    {
        $this->mouvementSortie = $mouvementSortie;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

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

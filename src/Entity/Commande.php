<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommandeStatut;
use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bon de commande fournisseur. Regroupe des lignes (article + taille + quantité).
 * Cycle : brouillon → commandée (date) → reçue partiellement → reçue.
 */
#[ORM\Entity(repositoryClass: CommandeRepository::class)]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 20, enumType: CommandeStatut::class)]
    private CommandeStatut $statut = CommandeStatut::BROUILLON;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateCommande = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, CommandeLigne> */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: CommandeLigne::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getStatut(): CommandeStatut
    {
        return $this->statut;
    }

    public function setStatut(CommandeStatut $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDateCommande(): ?\DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(?\DateTimeImmutable $dateCommande): static
    {
        $this->dateCommande = $dateCommande;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** @return Collection<int, CommandeLigne> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(CommandeLigne $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setCommande($this);
        }

        return $this;
    }

    public function getFournisseurLabel(): string
    {
        return $this->fournisseur?->getNom() ?? 'Sans fournisseur';
    }

    public function getQuantiteTotale(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getQuantite();
        }

        return $total;
    }

    /** Coût total estimé (pour le module Finance) — non affiché sur le PDF fournisseur. */
    public function getCoutTotal(): float
    {
        $total = 0.0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getQuantite() * (float) ($ligne->getPrixUnitaire() ?? 0.0);
        }

        return $total;
    }
}

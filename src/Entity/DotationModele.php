<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DotationModeleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modèle de dotation : liste configurable de ce qu'une personne doit recevoir.
 * Cloisonné par saison.
 */
#[ORM\Entity(repositoryClass: DotationModeleRepository::class)]
class DotationModele
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\Column(length: 150)]
    private string $nom;

    #[ORM\Column]
    private bool $actif = true;

    /** @var Collection<int, DotationModeleLigne> */
    #[ORM\OneToMany(mappedBy: 'modele', targetEntity: DotationModeleLigne::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }

    public function getSeason(): Season { return $this->season; }
    public function setSeason(Season $season): static { $this->season = $season; return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }

    /** @return Collection<int, DotationModeleLigne> */
    public function getLignes(): Collection { return $this->lignes; }

    public function addLigne(DotationModeleLigne $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setModele($this);
        }
        return $this;
    }

    public function removeLigne(DotationModeleLigne $ligne): static
    {
        $this->lignes->removeElement($ligne);
        return $this;
    }
}

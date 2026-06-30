<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DotationAffectationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Affecte un modèle de dotation à une cible. Une seule cible non-null par affectation :
 * licencié / dirigeant (individu) > équipe > catégorie FFF > rien (défaut saison).
 */
#[ORM\Entity(repositoryClass: DotationAffectationRepository::class)]
class DotationAffectation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DotationModele $modele;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid', onDelete: 'CASCADE')]
    private ?Licencie $licencie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid', onDelete: 'CASCADE')]
    private ?Dirigeant $dirigeant = null;

    public function getId(): int { return $this->id; }

    public function getSeason(): Season { return $this->season; }
    public function setSeason(Season $season): static { $this->season = $season; return $this; }

    public function getModele(): DotationModele { return $this->modele; }
    public function setModele(DotationModele $modele): static { $this->modele = $modele; return $this; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getTeam(): ?Team { return $this->team; }
    public function setTeam(?Team $team): static { $this->team = $team; return $this; }

    public function getLicencie(): ?Licencie { return $this->licencie; }
    public function setLicencie(?Licencie $licencie): static { $this->licencie = $licencie; return $this; }

    public function getDirigeant(): ?Dirigeant { return $this->dirigeant; }
    public function setDirigeant(?Dirigeant $dirigeant): static { $this->dirigeant = $dirigeant; return $this; }

    /** Niveau de priorité de la cible : plus haut = plus spécifique. */
    public function priorite(): int
    {
        return match (true) {
            $this->licencie !== null || $this->dirigeant !== null => 3, // individu
            $this->team !== null                                  => 2, // équipe
            $this->category !== null                              => 1, // catégorie
            default                                               => 0, // défaut saison
        };
    }

    /** Libellé lisible de la cible (pour l'admin). */
    public function cibleLabel(): string
    {
        return match (true) {
            $this->licencie !== null => 'Licencié — ' . $this->licencie->getNomPrenom(),
            $this->dirigeant !== null => 'Dirigeant — ' . $this->dirigeant->getNomPrenom(),
            $this->team !== null      => 'Équipe — ' . $this->team->getName(),
            $this->category !== null  => 'Catégorie — ' . $this->category->getLabel(),
            default                   => 'Par défaut (toute la saison)',
        };
    }
}

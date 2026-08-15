<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\GrilleTailleValeurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne de traduction : un libellé fournisseur et les tailles déclarées qu'il couvre.
 *
 * « 43-46 » couvre les pointures 43, 44, 45 et 46 ; « 128 » ne couvre que « 12 ans ». Une
 * taille déclarée n'est couverte que par une seule valeur d'une même grille — sinon la
 * traduction ne saurait pas laquelle choisir. `GrilleTailleService` refuse le chevauchement.
 */
#[ORM\Entity(repositoryClass: GrilleTailleValeurRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_grille_valeur_cible', columns: ['grille_id', 'cible_id'])]
class GrilleTailleValeur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'valeurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GrilleTaille $grille;

    /** Ce qui est écrit sur le carton du fournisseur, et donc dans le mouvement de stock. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Taille $cible;

    /**
     * Tailles déclarées que ce libellé habille. Relation et non liste de chaînes : une taille
     * couverte ne doit pas pouvoir disparaître du référentiel sous la grille qui s'en sert.
     *
     * @var Collection<int, Taille>
     */
    #[ORM\ManyToMany(targetEntity: Taille::class)]
    #[ORM\JoinTable(name: 'grille_taille_valeur_couverture')]
    private Collection $couvertures;

    public function __construct()
    {
        $this->couvertures = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getGrille(): GrilleTaille
    {
        return $this->grille;
    }

    public function setGrille(GrilleTaille $grille): static
    {
        $this->grille = $grille;

        return $this;
    }

    public function getCible(): Taille
    {
        return $this->cible;
    }

    public function setCible(Taille $cible): static
    {
        $this->cible = $cible;

        return $this;
    }

    /** @return Collection<int, Taille> */
    public function getCouvertures(): Collection
    {
        return $this->couvertures;
    }

    public function addCouverture(Taille $taille): static
    {
        if (!$this->couvertures->contains($taille)) {
            $this->couvertures->add($taille);
        }

        return $this;
    }

    public function removeCouverture(Taille $taille): static
    {
        $this->couvertures->removeElement($taille);

        return $this;
    }

    public function couvre(string $tailleDeclaree): bool
    {
        foreach ($this->couvertures as $taille) {
            if ($taille->getLibelle() === $tailleDeclaree) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> Identifiants des tailles couvertes, pour pré-cocher le multi-select. */
    public function idsCouverts(): array
    {
        $ids = [];
        foreach ($this->couvertures as $taille) {
            $ids[] = $taille->getId();
        }

        return $ids;
    }

    /** @return list<string> */
    public function libellesCouverts(): array
    {
        $libelles = [];
        foreach ($this->couvertures as $taille) {
            $libelles[] = $taille->getLibelle();
        }

        return $libelles;
    }
}

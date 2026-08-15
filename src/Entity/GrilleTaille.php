<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\TailleType;
use App\Repository\GrilleTailleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Table de traduction entre ce qu'une personne déclare et ce que le fournisseur étiquette.
 *
 * Un licencié dit « 44 » ou « 12 ans » — c'est tout ce qu'il sait dire de lui-même. Le
 * fournisseur, lui, vend ses chaussettes en « 43-46 » et ses vestes enfant en « 128 ». Sans
 * traduction, la dotation sort du stock une taille qui n'existe à aucun carton : le compteur
 * du « 44 » part en négatif pendant que le « 43-46 » ne bouge jamais.
 *
 * Une grille est un référentiel **niveau club**, non cloisonné par saison : un barème
 * fournisseur ne change pas au 1ᵉʳ juillet. Elle se rattache à un article
 * (`StockItem::grilleTaille`) ; un article sans grille se décline dans le vocabulaire déclaré
 * lui-même, ce qui reste le cas courant du maillot adulte.
 *
 * Les deux côtés de la traduction sont des `Taille` du référentiel, jamais du texte libre :
 * le libellé cible est recopié tel quel dans `stock_movement` et `dotation_besoin`, et la
 * saisie d'un mouvement ne propose que ce que le référentiel connaît.
 */
#[ORM\Entity(repositoryClass: GrilleTailleRepository::class)]
class GrilleTaille
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $nom;

    /** Échelle traduite : une grille de pointures ne couvre pas des tailles de vêtement. */
    #[ORM\Column(length: 20, enumType: TailleType::class)]
    private TailleType $type = TailleType::VETEMENT;

    /** @var Collection<int, GrilleTailleValeur> */
    #[ORM\OneToMany(mappedBy: 'grille', targetEntity: GrilleTailleValeur::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $valeurs;

    public function __construct()
    {
        $this->valeurs = new ArrayCollection();
    }

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

    public function getType(): TailleType
    {
        return $this->type;
    }

    public function setType(TailleType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** @return Collection<int, GrilleTailleValeur> */
    public function getValeurs(): Collection
    {
        return $this->valeurs;
    }

    public function addValeur(GrilleTailleValeur $valeur): static
    {
        if (!$this->valeurs->contains($valeur)) {
            $this->valeurs->add($valeur);
            $valeur->setGrille($this);
        }

        return $this;
    }

    public function removeValeur(GrilleTailleValeur $valeur): static
    {
        $this->valeurs->removeElement($valeur);

        return $this;
    }

    /**
     * Libellés fournisseur de la grille — les seules déclinaisons sous lesquelles le stock de
     * l'article se range. L'ordre n'est pas garanti ici : c'est celui du référentiel qui fait
     * foi, et il s'applique au moment de l'affichage.
     *
     * @return list<string>
     */
    public function libellesCibles(): array
    {
        $libelles = [];
        foreach ($this->valeurs as $valeur) {
            $libelles[] = $valeur->getCible()->getLibelle();
        }

        return $libelles;
    }

    /**
     * Libellé fournisseur qui couvre cette taille déclarée, ou null si aucun ne la couvre —
     * auquel cas le besoin de dotation reste sans taille et le suivi affiche « à renseigner ».
     */
    public function cibleQuiCouvre(string $tailleDeclaree): ?string
    {
        foreach ($this->valeurs as $valeur) {
            if ($valeur->couvre($tailleDeclaree)) {
                return $valeur->getCible()->getLibelle();
            }
        }

        return null;
    }
}

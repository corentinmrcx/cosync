<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\DotationEligibilite;
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

    /** Restreint cette ligne à une population selon la nature de sa licence. */
    #[ORM\Column(length: 20, enumType: DotationEligibilite::class, options: ['default' => 'tous'])]
    private DotationEligibilite $eligibilite = DotationEligibilite::TOUS;

    /** Cette option exige un texte saisi par le licencié (flocage au dos, par exemple). */
    #[ORM\Column(options: ['default' => false])]
    private bool $personnalisationRequise = false;

    /** Libellé de la question posée au licencié. Null → libellé par défaut du formulaire. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $personnalisationLabel = null;

    /** Longueur maximale du texte. Null → valeur par défaut du service de validation. */
    #[ORM\Column(nullable: true)]
    private ?int $personnalisationMaxLength = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getModele(): DotationModele
    {
        return $this->modele;
    }

    public function setModele(DotationModele $modele): static
    {
        $this->modele = $modele;

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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function isObligatoire(): bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): static
    {
        $this->obligatoire = $obligatoire;

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

    public function getEligibilite(): DotationEligibilite
    {
        return $this->eligibilite;
    }

    public function setEligibilite(DotationEligibilite $eligibilite): static
    {
        $this->eligibilite = $eligibilite;

        return $this;
    }

    public function isPersonnalisationRequise(): bool
    {
        return $this->personnalisationRequise;
    }

    public function setPersonnalisationRequise(bool $requise): static
    {
        $this->personnalisationRequise = $requise;

        return $this;
    }

    public function getPersonnalisationLabel(): ?string
    {
        return $this->personnalisationLabel;
    }

    public function setPersonnalisationLabel(?string $label): static
    {
        $this->personnalisationLabel = $label;

        return $this;
    }

    public function getPersonnalisationMaxLength(): ?int
    {
        return $this->personnalisationMaxLength;
    }

    public function setPersonnalisationMaxLength(?int $max): static
    {
        $this->personnalisationMaxLength = $max;

        return $this;
    }
}

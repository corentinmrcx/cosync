<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\TailleType;
use App\Repository\TailleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une taille du référentiel du club, réglée depuis /admin/club/tailles.
 *
 * Le libellé est la valeur réellement écrite dans les dossiers et les mouvements de stock :
 * le renommer ferait mentir tout l'existant, il est donc verrouillé dès qu'il sert quelque
 * part. Le reste — groupe, ordre, présence dans les formulaires — se change à volonté.
 *
 * `proposeeAuxLicencies` porte la distinction des deux publics : un licencié ne déclare que
 * ce qu'il sait dire de lui-même (« 12 ans »), là où le stock range ce que le fournisseur a
 * étiqueté sur le carton (« 128 », « L enfant »).
 */
#[ORM\Entity(repositoryClass: TailleRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_taille_type_libelle', columns: ['type', 'libelle'])]
class Taille
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 20)]
    private string $libelle;

    #[ORM\Column(length: 20, enumType: TailleType::class)]
    private TailleType $type = TailleType::VETEMENT;

    /** Intitulé du groupe dans les sélecteurs (« Tailles adultes »). Null = aucun groupe. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $groupe = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $proposeeAuxLicencies = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getId(): int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

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

    public function getGroupe(): ?string
    {
        return $this->groupe;
    }

    public function setGroupe(?string $groupe): static
    {
        $this->groupe = $groupe;

        return $this;
    }

    public function isProposeeAuxLicencies(): bool
    {
        return $this->proposeeAuxLicencies;
    }

    public function setProposeeAuxLicencies(bool $proposee): static
    {
        $this->proposeeAuxLicencies = $proposee;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}

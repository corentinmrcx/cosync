<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\DirigeantRole;
use App\Enum\DocumentCible;
use App\Repository\DocumentSignableRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document que le club fait lire et signer (règlement intérieur, charte…).
 *
 * Chaque document est une donnée, pas du code : l'admin le crée pour une saison,
 * choisit la population visée et, pour les dirigeants, qui est concerné.
 * Ajouter une charte ne demande donc ni migration ni déploiement.
 */
#[ORM\Entity(repositoryClass: DocumentSignableRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_document_signable_season_code', columns: ['season_id', 'code'])]
class DocumentSignable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /** Identifiant stable et lisible, unique dans la saison (ex : « charte_communication ») */
    #[ORM\Column(length: 60)]
    private string $code;

    /** Titre affiché dans le formulaire public, le PDF et l'admin */
    #[ORM\Column(length: 150)]
    private string $titre;

    /** Désignation dans une phrase : « …avoir lu et accepté {libelle} » */
    #[ORM\Column(length: 255)]
    private string $libelle;

    /** Contenu HTML rédigé dans l'éditeur admin */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contenuHtml = null;

    #[ORM\Column(length: 20, enumType: DocumentCible::class)]
    private DocumentCible $cible;

    /**
     * Rôles de dirigeants concernés, stockés par valeur d'enum.
     * Sans effet lorsque la cible est les licenciés.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Dirigeants nommément désignés, en plus des rôles ci-dessus.
     * Le référentiel des rôles étant fermé (Responsable foot, Responsable d'équipe,
     * Dirigeant), c'est le seul moyen d'adresser un document à une fonction qui n'est
     * pas un rôle — la charte communication, par exemple.
     *
     * @var Collection<int, Dirigeant>
     */
    #[ORM\ManyToMany(targetEntity: Dirigeant::class)]
    #[ORM\JoinTable(name: 'document_signable_dirigeant')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'dirigeant_uuid', referencedColumnName: 'uuid', onDelete: 'CASCADE')]
    private Collection $dirigeants;

    /** Un document inactif n'est plus demandé, mais ses signatures restent */
    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** Ordre des étapes de signature dans le formulaire public */
    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * Chemin d'archivage sous le dossier de saison sur Drive.
     * Dérivé du titre à la création, jamais saisi à la main.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $driveSegments = [];

    /** Préfixe du nom de fichier PDF archivé (ex : « ri » → ri_dupont_thomas.pdf) */
    #[ORM\Column(length: 30)]
    private string $filePrefix;

    public function __construct()
    {
        $this->dirigeants = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
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

    public function getContenuHtml(): ?string
    {
        return $this->contenuHtml;
    }

    public function setContenuHtml(?string $contenuHtml): static
    {
        $this->contenuHtml = $contenuHtml;

        return $this;
    }

    public function getCible(): DocumentCible
    {
        return $this->cible;
    }

    public function setCible(DocumentCible $cible): static
    {
        $this->cible = $cible;

        return $this;
    }

    /** @return DirigeantRole[] */
    public function getRoles(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value) => DirigeantRole::tryFrom($value),
            $this->roles,
        )));
    }

    /** @param DirigeantRole[] $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_map(
            static fn (DirigeantRole $role) => $role->value,
            $roles,
        )));

        return $this;
    }

    /** @return Collection<int, Dirigeant> */
    public function getDirigeants(): Collection
    {
        return $this->dirigeants;
    }

    public function addDirigeant(Dirigeant $dirigeant): static
    {
        if (!$this->dirigeants->contains($dirigeant)) {
            $this->dirigeants->add($dirigeant);
        }

        return $this;
    }

    public function removeDirigeant(Dirigeant $dirigeant): static
    {
        $this->dirigeants->removeElement($dirigeant);

        return $this;
    }

    public function clearDirigeants(): static
    {
        $this->dirigeants->clear();

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /** @return string[] */
    public function getDriveSegments(): array
    {
        return $this->driveSegments;
    }

    /** @param string[] $driveSegments */
    public function setDriveSegments(array $driveSegments): static
    {
        $this->driveSegments = $driveSegments;

        return $this;
    }

    public function getFilePrefix(): string
    {
        return $this->filePrefix;
    }

    public function setFilePrefix(string $filePrefix): static
    {
        $this->filePrefix = $filePrefix;

        return $this;
    }

    /** Le document vise-t-il tout le monde, faute de rôle ou de personne désignée ? */
    public function viseTousLesDirigeants(): bool
    {
        return $this->roles === [] && $this->dirigeants->isEmpty();
    }

    /**
     * Ce document est-il demandé à ce dirigeant ?
     * Ni rôle ni personne désignée = tous les dirigeants ; sinon, l'union des deux
     * ciblages (« les responsables d'équipe, plus Marie »).
     */
    /**
     * Qui est visé, en clair : les rôles puis les personnes nommément désignées.
     * Vide pour un document adressé à tous.
     */
    public function ciblesLabel(): string
    {
        if ($this->viseTousLesDirigeants()) {
            return '';
        }

        $cibles = array_map(static fn (DirigeantRole $role): string => $role->label(), $this->getRoles());

        foreach ($this->getDirigeants() as $dirigeant) {
            $cibles[] = $dirigeant->getNomPrenom();
        }

        return implode(', ', $cibles);
    }

    public function concerne(Dirigeant $dirigeant): bool
    {
        if ($this->viseTousLesDirigeants()) {
            return true;
        }

        if (in_array($dirigeant->getRole()->value, $this->roles, true)) {
            return true;
        }

        return $this->dirigeants->contains($dirigeant);
    }

    public function __toString(): string
    {
        return $this->titre;
    }
}

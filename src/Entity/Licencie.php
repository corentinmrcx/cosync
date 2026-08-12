<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\NatureLicence;
use App\Repository\LicencieRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LicencieRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_licencie_num_licence_season', columns: ['num_licence', 'season_id'])]
class Licencie
{
    /** Clé publique utilisée dans l'URL /inscription/{uuid} */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numLicence = null;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $prenom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateNaissance;

    /** Null si non encore renseigné par l'admin — requis pour envoyer le lien */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $voieRue = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    /** Assigné manuellement par l'admin */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /** Date d'expiration du lien public — null après consommation */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $formTokenExpiresAt = null;

    /** Date d'envoi effectif du lien d'inscription par mail — null tant qu'aucun mail n'est parti */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $linkSentAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $createdManually = false;

    /** Nature FootClubs de la licence — null tant qu'aucune source ne l'a renseignée. */
    #[ORM\Column(length: 30, nullable: true, enumType: NatureLicence::class)]
    private ?NatureLicence $natureLicence = null;

    /** Vrai si la nature a été fixée à la main par l'admin → l'import ne l'écrase plus. */
    #[ORM\Column(options: ['default' => false])]
    private bool $natureManuelle = false;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\OneToOne(mappedBy: 'licencie')]
    private ?DossierClub $dossierClub = null;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getNumLicence(): ?string
    {
        return $this->numLicence;
    }

    public function setNumLicence(?string $numLicence): static
    {
        $this->numLicence = $numLicence;

        return $this;
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

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNomPrenom(): string
    {
        return $this->nom . ' ' . $this->prenom;
    }

    public function getDateNaissance(): \DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getVoieRue(): ?string
    {
        return $this->voieRue;
    }

    public function setVoieRue(?string $voieRue): static
    {
        $this->voieRue = $voieRue;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

        return $this;
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

    public function getFormTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->formTokenExpiresAt;
    }

    public function setFormTokenExpiresAt(?\DateTimeImmutable $formTokenExpiresAt): static
    {
        $this->formTokenExpiresAt = $formTokenExpiresAt;

        return $this;
    }

    public function isFormTokenValid(): bool
    {
        return $this->formTokenExpiresAt !== null
            && $this->formTokenExpiresAt > new \DateTimeImmutable();
    }

    public function getLinkSentAt(): ?\DateTimeImmutable
    {
        return $this->linkSentAt;
    }

    public function setLinkSentAt(?\DateTimeImmutable $linkSentAt): static
    {
        $this->linkSentAt = $linkSentAt;

        return $this;
    }

    public function isCreatedManually(): bool
    {
        return $this->createdManually;
    }

    public function setCreatedManually(bool $createdManually): static
    {
        $this->createdManually = $createdManually;

        return $this;
    }

    public function getNatureLicence(): ?NatureLicence
    {
        return $this->natureLicence;
    }

    public function setNatureLicence(?NatureLicence $natureLicence): static
    {
        $this->natureLicence = $natureLicence;

        return $this;
    }

    public function isNatureManuelle(): bool
    {
        return $this->natureManuelle;
    }

    public function setNatureManuelle(bool $natureManuelle): static
    {
        $this->natureManuelle = $natureManuelle;

        return $this;
    }

    /**
     * Nouveau licencié au club ? Null si la nature n'est pas renseignée : l'inconnu
     * doit rester distinct du renouvellement (il conditionne les options de dotation).
     */
    public function estNouveau(): ?bool
    {
        return $this->natureLicence?->estNouveau();
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function getDossierClub(): ?DossierClub
    {
        return $this->dossierClub;
    }

    /** Tenu à jour par DossierClub::setLicencie() — ne pas appeler directement. */
    public function setDossierClub(DossierClub $dossierClub): static
    {
        $this->dossierClub = $dossierClub;

        return $this;
    }
}

<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DirigeantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DirigeantRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_dirigeant_num_licence_season', columns: ['num_licence', 'season_id'])]
class Dirigeant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numLicence = null;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $prenom;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DirigeantRole $role = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleHaut = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleBas = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $pointure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /** Si ce dirigeant est également un licencié joueur */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'uuid', onDelete: 'SET NULL')]
    private ?Licencie $licencie = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $createdManually = false;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    /** Date d'expiration du lien public — null avant premier envoi */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $formTokenExpiresAt = null;

    /** Date de soumission du formulaire public */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $formCompletedAt = null;

    public function __construct()
    {
        $this->uuid       = Uuid::v4();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getUuid(): Uuid { return $this->uuid; }

    public function getNumLicence(): ?string { return $this->numLicence; }
    public function setNumLicence(?string $numLicence): static { $this->numLicence = $numLicence; return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getPrenom(): string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }

    public function getNomPrenom(): string { return $this->nom . ' ' . $this->prenom; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $telephone): static { $this->telephone = $telephone; return $this; }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static { $this->dateNaissance = $dateNaissance; return $this; }

    public function getRole(): ?DirigeantRole { return $this->role; }
    public function setRole(?DirigeantRole $role): static { $this->role = $role; return $this; }

    public function getTailleHaut(): ?string { return $this->tailleHaut; }
    public function setTailleHaut(?string $tailleHaut): static { $this->tailleHaut = $tailleHaut; return $this; }

    public function getTailleBas(): ?string { return $this->tailleBas; }
    public function setTailleBas(?string $tailleBas): static { $this->tailleBas = $tailleBas; return $this; }

    public function getPointure(): ?string { return $this->pointure; }
    public function setPointure(?string $pointure): static { $this->pointure = $pointure; return $this; }

    public function getTeam(): ?Team { return $this->team; }
    public function setTeam(?Team $team): static { $this->team = $team; return $this; }

    public function getSeason(): Season { return $this->season; }
    public function setSeason(Season $season): static { $this->season = $season; return $this; }

    public function getLicencie(): ?Licencie { return $this->licencie; }
    public function setLicencie(?Licencie $licencie): static { $this->licencie = $licencie; return $this; }

    public function isCreatedManually(): bool { return $this->createdManually; }
    public function setCreatedManually(bool $createdManually): static { $this->createdManually = $createdManually; return $this; }

    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }

    public function getFormTokenExpiresAt(): ?\DateTimeImmutable { return $this->formTokenExpiresAt; }
    public function setFormTokenExpiresAt(?\DateTimeImmutable $formTokenExpiresAt): static { $this->formTokenExpiresAt = $formTokenExpiresAt; return $this; }

    public function getFormCompletedAt(): ?\DateTimeImmutable { return $this->formCompletedAt; }
    public function setFormCompletedAt(?\DateTimeImmutable $formCompletedAt): static { $this->formCompletedAt = $formCompletedAt; return $this; }

    public function isFormTokenValid(): bool
    {
        return $this->formTokenExpiresAt !== null
            && $this->formTokenExpiresAt > new \DateTimeImmutable();
    }
}

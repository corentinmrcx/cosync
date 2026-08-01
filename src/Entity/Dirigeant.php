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

    #[ORM\Column(nullable: true)]
    private ?bool $autorisationPhoto = null;

    /** « Transport des licenciés » — le dirigeant accepte de transporter des licenciés */
    #[ORM\Column(nullable: true)]
    private ?bool $volontaireTransport = null;

    /** Chemin local temporaire puis ID Drive de l'attestation de transport signée */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $attestationTransportDriveId = null;

    /** Chemin local temporaire puis ID Drive du règlement intérieur signé */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reglementSignePath = null;

    /** Date de signature du règlement intérieur par le dirigeant */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reglementSignedAt = null;

    /** Chemin local temporaire puis ID Drive de l'attestation de remise de clés signée */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $attestationCleSignePath = null;

    /** Date de signature de l'attestation de remise de clés */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attestationCleSignedAt = null;

    /** Expiration du lien public de signature de l'attestation — indépendant de formTokenExpiresAt */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attestationCleTokenExpiresAt = null;

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

    public function getAutorisationPhoto(): ?bool { return $this->autorisationPhoto; }
    public function setAutorisationPhoto(?bool $autorisationPhoto): static { $this->autorisationPhoto = $autorisationPhoto; return $this; }

    public function getVolontaireTransport(): ?bool { return $this->volontaireTransport; }
    public function setVolontaireTransport(?bool $volontaireTransport): static { $this->volontaireTransport = $volontaireTransport; return $this; }

    public function getAttestationTransportDriveId(): ?string { return $this->attestationTransportDriveId; }
    public function setAttestationTransportDriveId(?string $attestationTransportDriveId): static { $this->attestationTransportDriveId = $attestationTransportDriveId; return $this; }

    public function getReglementSignePath(): ?string { return $this->reglementSignePath; }
    public function setReglementSignePath(?string $reglementSignePath): static { $this->reglementSignePath = $reglementSignePath; return $this; }

    public function getReglementSignedAt(): ?\DateTimeImmutable { return $this->reglementSignedAt; }
    public function setReglementSignedAt(?\DateTimeImmutable $reglementSignedAt): static { $this->reglementSignedAt = $reglementSignedAt; return $this; }

    public function getAttestationCleSignePath(): ?string { return $this->attestationCleSignePath; }
    public function setAttestationCleSignePath(?string $attestationCleSignePath): static { $this->attestationCleSignePath = $attestationCleSignePath; return $this; }

    public function getAttestationCleSignedAt(): ?\DateTimeImmutable { return $this->attestationCleSignedAt; }
    public function setAttestationCleSignedAt(?\DateTimeImmutable $attestationCleSignedAt): static { $this->attestationCleSignedAt = $attestationCleSignedAt; return $this; }

    public function getAttestationCleTokenExpiresAt(): ?\DateTimeImmutable { return $this->attestationCleTokenExpiresAt; }
    public function setAttestationCleTokenExpiresAt(?\DateTimeImmutable $attestationCleTokenExpiresAt): static { $this->attestationCleTokenExpiresAt = $attestationCleTokenExpiresAt; return $this; }

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

    /**
     * Le règlement intérieur est-il déjà signé pour cette personne ?
     * Vrai si le dirigeant l'a signé lui-même, ou si le licencié auquel il est
     * rattaché (dirigeant-joueur) l'a déjà signé via son propre dossier.
     */
    public function hasSignedReglement(): bool
    {
        if ($this->reglementSignePath !== null) {
            return true;
        }

        return $this->licencie?->getDossierClub()?->isSigned() === true;
    }

    /** Le dirigeant doit-il signer le règlement dans son formulaire public ? */
    public function needsReglementSignature(): bool
    {
        return !$this->hasSignedReglement();
    }

    /**
     * L'attestation de remise de clés est-elle signée ?
     * Volontairement hors de isPublicFormComplete() : elle ne concerne que les
     * détenteurs de clés, pas le parcours dirigeant standard.
     */
    public function hasSignedAttestationCle(): bool
    {
        return $this->attestationCleSignePath !== null;
    }

    public function isAttestationCleTokenValid(): bool
    {
        return $this->attestationCleTokenExpiresAt !== null
            && $this->attestationCleTokenExpiresAt > new \DateTimeImmutable();
    }

    /**
     * Source de vérité de la complétude du dossier public dirigeant.
     * - Transport : requis pour tous ; attestation requise si volontaire.
     * - Règlement intérieur : requis sauf s'il est déjà signé (dirigeant-joueur
     *   dont le licencié a signé).
     * - Dirigeant-joueur (lié à un licencié) : taille + droit image proviennent
     *   du dossier licencié → non requis ici.
     */
    public function isPublicFormComplete(): bool
    {
        if ($this->volontaireTransport === null) {
            return false;
        }
        if ($this->volontaireTransport === true && $this->attestationTransportDriveId === null) {
            return false;
        }
        if ($this->needsReglementSignature()) {
            return false;
        }

        if ($this->licencie !== null) {
            return true;
        }

        return $this->tailleHaut !== null && $this->tailleBas !== null
            && $this->pointure !== null && $this->autorisationPhoto !== null;
    }
}

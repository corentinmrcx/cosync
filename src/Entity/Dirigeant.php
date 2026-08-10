<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\DirigeantRole;
use App\Repository\DirigeantRepository;
use App\Service\Drive\DrivePath;
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

    #[ORM\Column(length: 32, enumType: DirigeantRole::class, options: ['default' => 'dirigeant'])]
    private DirigeantRole $role = DirigeantRole::DIRIGEANT;

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

    /*
     * Le règlement intérieur des dirigeants avait ici ses deux colonnes dédiées.
     * Les signatures vivent désormais dans DocumentSignature, ce qui permet d'en
     * demander un nombre quelconque (chartes par rôle) sans toucher au schéma.
     * Les colonnes reglement_signe_path et reglement_signed_at subsistent en base,
     * dé-mappées, le temps de valider la bascule (migration Version20260807233000).
     */

    /*
     * L'attestation de remise de clés avait ici ses trois colonnes dédiées. Elle est
     * désormais portée par AttestationCle, rattachée au détenteur — hors saison — et
     * rejouée chaque année, ce qu'un champ sur un Dirigeant cloisonné par saison ne
     * savait pas faire. Les colonnes attestation_cle_signe_path, attestation_cle_signed_at
     * et attestation_cle_token_expires_at subsistent en base, dé-mappées, le temps de
     * valider la bascule (migration Version20260810120200).
     */

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

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getRole(): DirigeantRole
    {
        return $this->role;
    }

    public function setRole(DirigeantRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getTailleHaut(): ?string
    {
        return $this->tailleHaut;
    }

    public function setTailleHaut(?string $tailleHaut): static
    {
        $this->tailleHaut = $tailleHaut;

        return $this;
    }

    public function getTailleBas(): ?string
    {
        return $this->tailleBas;
    }

    public function setTailleBas(?string $tailleBas): static
    {
        $this->tailleBas = $tailleBas;

        return $this;
    }

    public function getPointure(): ?string
    {
        return $this->pointure;
    }

    public function setPointure(?string $pointure): static
    {
        $this->pointure = $pointure;

        return $this;
    }

    public function getAutorisationPhoto(): ?bool
    {
        return $this->autorisationPhoto;
    }

    public function setAutorisationPhoto(?bool $autorisationPhoto): static
    {
        $this->autorisationPhoto = $autorisationPhoto;

        return $this;
    }

    public function getVolontaireTransport(): ?bool
    {
        return $this->volontaireTransport;
    }

    public function setVolontaireTransport(?bool $volontaireTransport): static
    {
        $this->volontaireTransport = $volontaireTransport;

        return $this;
    }

    public function getAttestationTransportDriveId(): ?string
    {
        return $this->attestationTransportDriveId;
    }

    public function setAttestationTransportDriveId(?string $attestationTransportDriveId): static
    {
        $this->attestationTransportDriveId = $attestationTransportDriveId;

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

    public function getLicencie(): ?Licencie
    {
        return $this->licencie;
    }

    public function setLicencie(?Licencie $licencie): static
    {
        $this->licencie = $licencie;

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

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
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

    public function getFormCompletedAt(): ?\DateTimeImmutable
    {
        return $this->formCompletedAt;
    }

    public function setFormCompletedAt(?\DateTimeImmutable $formCompletedAt): static
    {
        $this->formCompletedAt = $formCompletedAt;

        return $this;
    }

    public function isFormTokenValid(): bool
    {
        return $this->formTokenExpiresAt !== null
            && $this->formTokenExpiresAt > new \DateTimeImmutable();
    }

    /**
     * Complétude des informations portées par le dirigeant lui-même.
     * - Transport : requis pour tous ; attestation requise si volontaire.
     * - Dirigeant-joueur (lié à un licencié) : taille + droit image proviennent
     *   du dossier licencié → non requis ici.
     *
     * Les documents à signer n'entrent pas dans ce calcul : ils dépendent de la
     * saison et du rôle, donc d'une requête. La complétude complète du dossier se
     * demande à DirigeantDossierCompletion::isComplete().
     */
    public function isBaseFormComplete(): bool
    {
        if ($this->volontaireTransport === null) {
            return false;
        }
        if ($this->volontaireTransport === true && $this->attestationTransportDriveId === null) {
            return false;
        }

        if ($this->licencie !== null) {
            return true;
        }

        return $this->tailleHaut !== null && $this->tailleBas !== null
            && $this->pointure !== null && $this->autorisationPhoto !== null;
    }

    /** Le PDF est-il sur Drive ? Tant qu'il ne l'est pas, la colonne porte un chemin local. */
    public function attestationTransportEstArchivee(): bool
    {
        return DrivePath::estArchive($this->getAttestationTransportDriveId());
    }
}

<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttestationCleRepository;
use App\Service\Drive\DrivePath;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Engagement annuel d'un détenteur de clés : « je reconnais détenir N clés du local
 * et je m'engage à… », signé pour une saison donnée.
 *
 * Le détenteur, lui, est hors saison (cf. Detenteur) : c'est bien l'attestation qui
 * se rejoue chaque année, pas la détention. La table est append-only comme le
 * registre — une re-signature en cours de saison (clé supplémentaire remise) ajoute
 * une ligne, elle n'écrase pas la précédente : les deux PDF font foi à leur date.
 */
#[ORM\Entity(repositoryClass: AttestationCleRepository::class)]
#[ORM\Index(name: 'idx_attestation_cle_detenteur_season', columns: ['detenteur_id', 'season_id'])]
class AttestationCle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** Clé publique du lien de signature — le détenteur n'a ni compte ni session */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** Pas de onDelete : une attestation signée survit à la sortie de son signataire */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Detenteur $detenteur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /** Date d'envoi du lien de signature — null tant que la demande n'est pas partie */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demandeEnvoyeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    /** Nombre de clés attesté, figé à la signature : l'attestation dit ce qu'elle disait */
    #[ORM\Column(nullable: true)]
    private ?int $nbCles = null;

    /** Date de remise mentionnée dans l'attestation, telle que le registre la connaissait */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $remiseLe = null;

    /** Chemin local temporaire puis ID Drive du PDF signé */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $drivePath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getDetenteur(): Detenteur
    {
        return $this->detenteur;
    }

    public function setDetenteur(Detenteur $detenteur): static
    {
        $this->detenteur = $detenteur;

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

    public function getDemandeEnvoyeeLe(): ?\DateTimeImmutable
    {
        return $this->demandeEnvoyeeLe;
    }

    public function setDemandeEnvoyeeLe(?\DateTimeImmutable $demandeEnvoyeeLe): static
    {
        $this->demandeEnvoyeeLe = $demandeEnvoyeeLe;

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(?\DateTimeImmutable $signedAt): static
    {
        $this->signedAt = $signedAt;

        return $this;
    }

    public function getNbCles(): ?int
    {
        return $this->nbCles;
    }

    public function setNbCles(?int $nbCles): static
    {
        $this->nbCles = $nbCles;

        return $this;
    }

    public function getRemiseLe(): ?\DateTimeImmutable
    {
        return $this->remiseLe;
    }

    public function setRemiseLe(?\DateTimeImmutable $remiseLe): static
    {
        $this->remiseLe = $remiseLe;

        return $this;
    }

    public function getDrivePath(): ?string
    {
        return $this->drivePath;
    }

    public function setDrivePath(?string $drivePath): static
    {
        $this->drivePath = $drivePath;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function estSignee(): bool
    {
        return $this->signedAt !== null;
    }

    public function isTokenValid(): bool
    {
        return $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt > new \DateTimeImmutable();
    }

    /** Le PDF est-il sur Drive ? Tant qu'il ne l'est pas, la colonne porte un chemin local. */
    public function estArchivee(): bool
    {
        return DrivePath::estArchive($this->drivePath);
    }
}

<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeasonRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeasonRepository::class)]
class Season
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 20, unique: true)]
    private string $label;

    /** Cotisation appliquée par défaut (€), pour un licencié sans équipe ou une équipe sans cotisation définie. */
    #[ORM\Column(options: ['default' => 0])]
    private int $cotisationDefaut = 0;

    /** Règlement intérieur signé par les licenciés dans le parcours d'inscription */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reglementText = null;

    /** Règlement intérieur propre aux dirigeants, signé dans le parcours dirigeant */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reglementDirigeantText = null;

    /** Texte de l'attestation de remise signée par les détenteurs de clés du club house */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attestationCleText = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getCotisationDefaut(): int
    {
        return $this->cotisationDefaut;
    }

    public function setCotisationDefaut(int $cotisationDefaut): static
    {
        $this->cotisationDefaut = $cotisationDefaut;
        return $this;
    }

    public function getReglementText(): ?string
    {
        return $this->reglementText;
    }

    public function setReglementText(?string $reglementText): static
    {
        $this->reglementText = $reglementText;
        return $this;
    }

    public function getReglementDirigeantText(): ?string
    {
        return $this->reglementDirigeantText;
    }

    public function setReglementDirigeantText(?string $reglementDirigeantText): static
    {
        $this->reglementDirigeantText = $reglementDirigeantText;
        return $this;
    }

    public function getAttestationCleText(): ?string
    {
        return $this->attestationCleText;
    }

    public function setAttestationCleText(?string $attestationCleText): static
    {
        $this->attestationCleText = $attestationCleText;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

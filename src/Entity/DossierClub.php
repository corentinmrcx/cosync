<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\DossierClubRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DossierClubRepository::class)]
class DossierClub
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\OneToOne(inversedBy: 'dossierClub')]
    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'uuid')]
    private Licencie $licencie;

    /** XS / S / M / L / XL / XXL ou taille enfant (ex: "10 ans") */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleHaut = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tailleBas = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $pointure = null;

    #[ORM\Column(nullable: true)]
    private ?bool $autorisationPhoto = null;

    /** null si non applicable (seniors) */
    #[ORM\Column(nullable: true)]
    private ?bool $autorisationTransportDirigeants = null;

    /** null si non applicable (seniors) */
    #[ORM\Column(nullable: true)]
    private ?bool $autorisationTransportParents = null;

    /** Modes de paiement déclarés par le licencié dans le formulaire (stockés comme tableau de valeurs string) */
    #[ORM\Column(type: 'json')]
    private array $paymentIntentions = [];

    #[ORM\Column]
    private bool $isSigned = false;

    /** Chemin Drive du PDF après upload */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $signaturePath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signatureDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $formCompletedAt = null;

    #[ORM\Column(enumType: LicenceStatus::class)]
    private LicenceStatus $status = LicenceStatus::LINK_SENT;

    public function getId(): int
    {
        return $this->id;
    }

    public function getLicencie(): Licencie
    {
        return $this->licencie;
    }

    public function setLicencie(Licencie $licencie): static
    {
        $this->licencie = $licencie;
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

    public function getAutorisationTransportDirigeants(): ?bool
    {
        return $this->autorisationTransportDirigeants;
    }

    public function setAutorisationTransportDirigeants(?bool $autorisationTransportDirigeants): static
    {
        $this->autorisationTransportDirigeants = $autorisationTransportDirigeants;
        return $this;
    }

    public function getAutorisationTransportParents(): ?bool
    {
        return $this->autorisationTransportParents;
    }

    public function setAutorisationTransportParents(?bool $autorisationTransportParents): static
    {
        $this->autorisationTransportParents = $autorisationTransportParents;
        return $this;
    }

    /** @return PaymentMode[] */
    public function getPaymentIntentions(): array
    {
        return array_map(
            fn(string $v) => PaymentMode::from($v),
            $this->paymentIntentions,
        );
    }

    /** @param PaymentMode[] $modes */
    public function setPaymentIntentions(array $modes): static
    {
        $this->paymentIntentions = array_map(fn(PaymentMode $m) => $m->value, $modes);
        return $this;
    }

    public function isSigned(): bool
    {
        return $this->isSigned;
    }

    public function setIsSigned(bool $isSigned): static
    {
        $this->isSigned = $isSigned;
        return $this;
    }

    public function getSignaturePath(): ?string
    {
        return $this->signaturePath;
    }

    public function setSignaturePath(?string $signaturePath): static
    {
        $this->signaturePath = $signaturePath;
        return $this;
    }

    public function getSignatureDate(): ?\DateTimeImmutable
    {
        return $this->signatureDate;
    }

    public function setSignatureDate(?\DateTimeImmutable $signatureDate): static
    {
        $this->signatureDate = $signatureDate;
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

    public function getStatus(): LicenceStatus
    {
        return $this->status;
    }

    public function setStatus(LicenceStatus $status): static
    {
        $this->status = $status;
        return $this;
    }
}

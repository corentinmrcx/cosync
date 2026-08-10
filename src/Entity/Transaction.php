<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMode;
use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_transaction_external_payment', columns: ['external_payment_id'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'uuid')]
    private Licencie $licencie;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $montant;

    #[ORM\Column(enumType: PaymentMode::class)]
    private PaymentMode $mode;

    /** Numéro de chèque, référence virement, etc. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    /**
     * Identifiant du paiement chez le prestataire en ligne (HelloAsso).
     * Contrainte d'unicité : garantit qu'un encaissement notifié plusieurs fois
     * (webhook rejoué, page de retour en parallèle) n'est enregistré qu'une seule fois.
     * Null pour tous les paiements saisis manuellement — PostgreSQL autorise les NULL multiples.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalPaymentId = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $datePaiement;

    /** Null pour les paiements encaissés automatiquement en ligne (HelloAsso) : aucun dirigeant ne les saisit */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $confirmedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

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

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getMode(): PaymentMode
    {
        return $this->mode;
    }

    public function setMode(PaymentMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getExternalPaymentId(): ?string
    {
        return $this->externalPaymentId;
    }

    public function setExternalPaymentId(?string $externalPaymentId): static
    {
        $this->externalPaymentId = $externalPaymentId;

        return $this;
    }

    public function getDatePaiement(): \DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(\DateTimeImmutable $datePaiement): static
    {
        $this->datePaiement = $datePaiement;

        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): static
    {
        $this->confirmedBy = $confirmedBy;

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
}

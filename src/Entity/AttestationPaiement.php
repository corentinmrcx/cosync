<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\Civilite;
use App\Enum\LienParente;
use App\Enum\PaymentMode;
use App\Repository\AttestationPaiementRepository;
use App\Service\Drive\DrivePath;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Attestation remise à qui a réglé une licence, pour être présentée à un employeur ou
 * à un comité d'entreprise.
 *
 * Tout ce que le document affirme est **figé à l'émission**, comme le nombre de clés
 * d'une AttestationCle : un paiement peut être corrigé ou supprimé après coup
 * (`admin_licencies_delete_payment`), et une attestation déjà remise à un tiers doit
 * continuer de dire ce qu'elle disait le jour où elle a été signée. La table est
 * append-only — une réémission ajoute une ligne, elle n'en écrase aucune.
 */
#[ORM\Entity(repositoryClass: AttestationPaiementRepository::class)]
#[ORM\Index(name: 'idx_attestation_paiement_licencie', columns: ['licencie_uuid'])]
class AttestationPaiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** Identifiant stable du document, repris dans le nom du fichier archivé. */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** Pas de onDelete : la suppression d'une fiche passe par SuppressionFicheService, qui refuse dès qu'un paiement existe. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'licencie_uuid', referencedColumnName: 'uuid', nullable: false)]
    private Licencie $licencie;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /* ── Ce que le document dit du licencié, recopié à l'émission ── */

    #[ORM\Column(length: 100)]
    private string $licencieNom;

    #[ORM\Column(length: 100)]
    private string $licenciePrenom;

    /* ── Le destinataire : la personne qui a payé ── */

    #[ORM\Column(length: 10, enumType: Civilite::class)]
    private Civilite $destinataireCivilite;

    #[ORM\Column(length: 100)]
    private string $destinataireNom;

    #[ORM\Column(length: 100)]
    private string $destinatairePrenom;

    #[ORM\Column(length: 20, enumType: LienParente::class)]
    private LienParente $lienParente;

    /* ── Le paiement attesté ── */

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $montant;

    /**
     * « cent vingt euros ». Figé et non recalculé : une correction ultérieure des règles
     * d'écriture ne doit pas faire diverger un document déjà remis de l'exemplaire
     * archivé qui en fait foi.
     */
    #[ORM\Column(length: 255)]
    private string $montantEnLettres;

    /** Date du dernier versement pris en compte — celle qui solde la licence. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $datePaiement;

    /**
     * Modes employés, dédoublonnés et figés.
     *
     * @var list<string> valeurs de PaymentMode
     */
    #[ORM\Column(type: 'json')]
    private array $modes = [];

    /**
     * Paiements attestés. La jointure tombe si un paiement est supprimé plus tard : ce
     * lien n'est qu'une trace de rapprochement, la vérité du document reste dans les
     * colonnes figées ci-dessus.
     *
     * @var Collection<int, Transaction>
     */
    #[ORM\ManyToMany(targetEntity: Transaction::class)]
    #[ORM\JoinTable(name: 'attestation_paiement_transaction')]
    #[ORM\JoinColumn(name: 'attestation_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'transaction_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $transactions;

    /* ── Le signataire, tel qu'il était au moment de l'émission ── */

    #[ORM\Column(length: 10, enumType: Civilite::class)]
    private Civilite $signataireCivilite;

    #[ORM\Column(length: 100)]
    private string $signataireNom;

    #[ORM\Column(length: 100)]
    private string $signataireQualite;

    /* ── Traçabilité ── */

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $generatedBy = null;

    /** Chemin local absolu du PDF, puis ID Drive une fois archivé (cf. DrivePath). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $drivePath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeeLe = null;

    /** Adresse réellement visée : le payeur n'a pas forcément le mail du licencié. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $envoyeeA = null;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->generatedAt = new \DateTimeImmutable();
        $this->transactions = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
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

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function setSeason(Season $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getLicencieNom(): string
    {
        return $this->licencieNom;
    }

    public function setLicencieNom(string $licencieNom): static
    {
        $this->licencieNom = $licencieNom;

        return $this;
    }

    public function getLicenciePrenom(): string
    {
        return $this->licenciePrenom;
    }

    public function setLicenciePrenom(string $licenciePrenom): static
    {
        $this->licenciePrenom = $licenciePrenom;

        return $this;
    }

    /** Tel qu'il s'écrit dans la phrase : « concernant son fils : Maxence Marcoux ». */
    public function getLicencieNomPrenom(): string
    {
        return $this->licenciePrenom . ' ' . $this->licencieNom;
    }

    public function getDestinataireCivilite(): Civilite
    {
        return $this->destinataireCivilite;
    }

    public function setDestinataireCivilite(Civilite $destinataireCivilite): static
    {
        $this->destinataireCivilite = $destinataireCivilite;

        return $this;
    }

    public function getDestinataireNom(): string
    {
        return $this->destinataireNom;
    }

    public function setDestinataireNom(string $destinataireNom): static
    {
        $this->destinataireNom = $destinataireNom;

        return $this;
    }

    public function getDestinatairePrenom(): string
    {
        return $this->destinatairePrenom;
    }

    public function setDestinatairePrenom(string $destinatairePrenom): static
    {
        $this->destinatairePrenom = $destinatairePrenom;

        return $this;
    }

    /** « Mme Ericka Marcoux » — la forme employée dans le corps de l'attestation. */
    public function getDestinataireComplet(): string
    {
        return sprintf(
            '%s %s %s',
            $this->destinataireCivilite->label(),
            $this->destinatairePrenom,
            $this->destinataireNom,
        );
    }

    public function getLienParente(): LienParente
    {
        return $this->lienParente;
    }

    public function setLienParente(LienParente $lienParente): static
    {
        $this->lienParente = $lienParente;

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

    public function getMontantEnLettres(): string
    {
        return $this->montantEnLettres;
    }

    public function setMontantEnLettres(string $montantEnLettres): static
    {
        $this->montantEnLettres = $montantEnLettres;

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

    /** @return list<PaymentMode> */
    public function getModes(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $valeur): ?PaymentMode => PaymentMode::tryFrom($valeur),
            $this->modes,
        )));
    }

    /** @param list<PaymentMode> $modes */
    public function setModes(array $modes): static
    {
        $this->modes = array_map(static fn (PaymentMode $m): string => $m->value, $modes);

        return $this;
    }

    /** « Chèque, Espèces » — un paiement fractionné a pu employer plusieurs modes. */
    public function getModesLabel(): string
    {
        return implode(', ', array_map(static fn (PaymentMode $m): string => $m->label(), $this->getModes()));
    }

    /** @return Collection<int, Transaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function getSignataireCivilite(): Civilite
    {
        return $this->signataireCivilite;
    }

    public function setSignataireCivilite(Civilite $signataireCivilite): static
    {
        $this->signataireCivilite = $signataireCivilite;

        return $this;
    }

    public function getSignataireNom(): string
    {
        return $this->signataireNom;
    }

    public function setSignataireNom(string $signataireNom): static
    {
        $this->signataireNom = $signataireNom;

        return $this;
    }

    public function getSignataireQualite(): string
    {
        return $this->signataireQualite;
    }

    public function setSignataireQualite(string $signataireQualite): static
    {
        $this->signataireQualite = $signataireQualite;

        return $this;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getGeneratedBy(): ?User
    {
        return $this->generatedBy;
    }

    public function setGeneratedBy(?User $generatedBy): static
    {
        $this->generatedBy = $generatedBy;

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

    public function getEnvoyeeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function setEnvoyeeLe(?\DateTimeImmutable $envoyeeLe): static
    {
        $this->envoyeeLe = $envoyeeLe;

        return $this;
    }

    public function getEnvoyeeA(): ?string
    {
        return $this->envoyeeA;
    }

    public function setEnvoyeeA(?string $envoyeeA): static
    {
        $this->envoyeeA = $envoyeeA;

        return $this;
    }

    /** Le PDF est-il sur Drive ? Tant qu'il ne l'est pas, la colonne porte un chemin local. */
    public function estArchivee(): bool
    {
        return DrivePath::estArchive($this->drivePath);
    }

    public function estEnvoyee(): bool
    {
        return $this->envoyeeLe !== null;
    }
}

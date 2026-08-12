<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\DossierClubRepository;
use App\Service\Drive\DrivePath;
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

    /** null si majeur — autorisation d'intervenir en cas d'accident */
    #[ORM\Column(nullable: true)]
    private ?bool $autorisationAccident = null;

    /** null si majeur — le parent accepte de transporter d'autres enfants */
    #[ORM\Column(nullable: true)]
    private ?bool $volontaireTransport = null;

    /** Chemin local temporaire puis ID Drive de l'attestation transport bénévole */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $attestationTransportDriveId = null;

    /**
     * Modes de paiement déclarés par le licencié dans le formulaire (stockés comme tableau de valeurs string).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $paymentIntentions = [];

    /** Nature du paiement saisie librement quand le licencié a choisi « Autre » (tickets MSA…) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $paymentAutrePrecision = null;

    /**
     * Choix de dotation faits au formulaire : { groupeChoix: stockItemId }. Null si aucun choix proposé.
     *
     * @var array<string, int|string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dotationChoix = null;

    /**
     * Textes de personnalisation saisis au formulaire : { groupeChoix: texte }.
     * Même espace de clés que dotationChoix — les deux se lisent côte à côte, et le texte
     * survit à un changement d'option à l'intérieur du groupe.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dotationPersonnalisation = null;

    /*
     * La signature du règlement intérieur vivait ici (is_signed, signature_path,
     * signature_date). Elle est désormais une ligne de DocumentSignature, ce qui permet
     * de demander plusieurs documents au même licencié. Les colonnes subsistent en base,
     * dé-mappées, le temps de valider la bascule (migration Version20260807233000).
     */

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $formCompletedAt = null;

    #[ORM\Column(enumType: LicenceStatus::class)]
    private LicenceStatus $status = LicenceStatus::IMPORTED;

    /** ID de la dernière intention de paiement HelloAsso créée pour ce dossier */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $helloassoCheckoutIntentId = null;

    /**
     * Date de création de cette intention. Sert à borner la réconciliation
     * (app:helloasso:sync-paiements) : une intention abandonnée depuis longtemps
     * n'est plus interrogée indéfiniment auprès de l'API HelloAsso.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $helloassoCheckoutStartedAt = null;

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
        // Le côté inverse n'est peuplé par Doctrine qu'au rechargement : sans cette ligne,
        // `$licencie->getDossierClub()` rend null pendant toute la requête qui vient de créer
        // le dossier, et le code qui fait avancer le statut ne trouve rien à faire avancer.
        $licencie->setDossierClub($this);

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
            fn (string $v) => PaymentMode::from($v),
            $this->paymentIntentions,
        );
    }

    /** @param PaymentMode[] $modes */
    public function setPaymentIntentions(array $modes): static
    {
        $this->paymentIntentions = array_map(fn (PaymentMode $m) => $m->value, $modes);

        return $this;
    }

    public function getPaymentAutrePrecision(): ?string
    {
        return $this->paymentAutrePrecision;
    }

    public function setPaymentAutrePrecision(?string $precision): static
    {
        $this->paymentAutrePrecision = $precision ?: null;

        return $this;
    }

    /** @return array<string, int>|null */
    public function getDotationChoix(): ?array
    {
        return $this->dotationChoix;
    }

    /** @param array<string, int>|null $dotationChoix */
    public function setDotationChoix(?array $dotationChoix): static
    {
        $this->dotationChoix = $dotationChoix ?: null;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getDotationPersonnalisation(): ?array
    {
        return $this->dotationPersonnalisation;
    }

    /** @param array<string, string>|null $dotationPersonnalisation */
    public function setDotationPersonnalisation(?array $dotationPersonnalisation): static
    {
        $this->dotationPersonnalisation = $dotationPersonnalisation ?: null;

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

    public function getAutorisationAccident(): ?bool
    {
        return $this->autorisationAccident;
    }

    public function setAutorisationAccident(?bool $autorisationAccident): static
    {
        $this->autorisationAccident = $autorisationAccident;

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

    public function getHelloassoCheckoutIntentId(): ?string
    {
        return $this->helloassoCheckoutIntentId;
    }

    public function setHelloassoCheckoutIntentId(?string $helloassoCheckoutIntentId): static
    {
        $this->helloassoCheckoutIntentId = $helloassoCheckoutIntentId;

        return $this;
    }

    public function getHelloassoCheckoutStartedAt(): ?\DateTimeImmutable
    {
        return $this->helloassoCheckoutStartedAt;
    }

    public function setHelloassoCheckoutStartedAt(?\DateTimeImmutable $helloassoCheckoutStartedAt): static
    {
        $this->helloassoCheckoutStartedAt = $helloassoCheckoutStartedAt;

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

    /** Le PDF est-il sur Drive ? Tant qu'il ne l'est pas, la colonne porte un chemin local. */
    public function attestationTransportEstArchivee(): bool
    {
        return DrivePath::estArchive($this->getAttestationTransportDriveId());
    }

    /** La cotisation est-elle soldée ? C'est ce que porte le statut VALIDATED. */
    public function estValidee(): bool
    {
        return $this->status === LicenceStatus::VALIDATED;
    }
}

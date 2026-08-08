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

    /*
     * Les textes des règlements intérieurs vivaient ici, une colonne par destinataire.
     * Ils sont désormais portés par DocumentSignable, créé depuis l'admin : ajouter un
     * document ne demande plus de migration. Les colonnes reglement_text et
     * reglement_dirigeant_text subsistent en base, dé-mappées, le temps de valider la
     * bascule en production (voir la migration Version20260807233000).
     */

    /** Texte de l'attestation de remise signée par les détenteurs de clés du club house */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attestationCleText = null;

    /*
     * Coordonnées bancaires du club, affichées au licencié qui règle par virement
     * (formulaire, page de confirmation, mail de confirmation). Portées par la saison
     * plutôt que codées en dur : le bureau change de banque sans redéploiement, et il
     * n'existe qu'une seule source. Nullables — une saison sans IBAN masque simplement
     * l'option « virement ».
     */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $titulaireCompte = null;

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

    public function getAttestationCleText(): ?string
    {
        return $this->attestationCleText;
    }

    public function setAttestationCleText(?string $attestationCleText): static
    {
        $this->attestationCleText = $attestationCleText;
        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $this->normaliser($iban);
        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $this->normaliser($bic);
        return $this;
    }

    public function getTitulaireCompte(): ?string
    {
        return $this->titulaireCompte;
    }

    public function setTitulaireCompte(?string $titulaireCompte): static
    {
        $this->titulaireCompte = $this->normaliser($titulaireCompte);
        return $this;
    }

    /**
     * Le virement n'est proposé que si le club a de quoi le recevoir : afficher l'option
     * sans donner d'IBAN enverrait le licencié dans le mur.
     */
    public function accepteVirement(): bool
    {
        return $this->iban !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Un champ vidé dans le formulaire arrive en chaîne vide : le stocker fausserait accepteVirement(). */
    private function normaliser(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}

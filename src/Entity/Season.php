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

    /** Texte de l'attestation de remise signée par les détenteurs de clés du local */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attestationCleText = null;

    /*
     * Les coordonnées bancaires ont vécu ici, une colonne par champ. Le RIB appartient au
     * club et non à la saison : il est porté par ClubSettings depuis Version20260809160000.
     * Les colonnes iban, bic et titulaire_compte subsistent en base, dé-mappées, le temps
     * de valider la bascule en production.
     */

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

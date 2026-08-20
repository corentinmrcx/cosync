<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DetenteurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personne détenant une clé du local, au niveau du club et non de la saison.
 *
 * Un trousseau ne change pas de main au 1er juillet : rattacher la détention à un
 * Dirigeant, cloisonné par saison, faisait repartir le registre à zéro chaque été
 * alors que les clés étaient toujours dehors. Le détenteur vit donc hors saison ;
 * seule l'attestation qu'il signe est annuelle (cf. AttestationCle).
 *
 * En pratique, un détenteur se crée depuis un dirigeant de la saison — mais rien
 * n'oblige à ce qu'il en soit un : la mairie ou une entreprise d'entretien peuvent
 * détenir une clé sans figurer à l'effectif.
 */
#[ORM\Entity(repositoryClass: DetenteurRepository::class)]
class Detenteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $prenom;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /**
     * Numéro de licence FFF, quand la personne en a un. C'est la seule clé fiable
     * pour retrouver le dirigeant correspondant d'une saison à l'autre — le nom ne
     * l'est pas (orthographes multiples, homonymes).
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numLicence = null;

    /** Qualité de la personne quand elle n'est pas dirigeante : « Mairie de Soudron », « Entreprise Ménage+ »… */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $qualite = null;

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

    public function getNumLicence(): ?string
    {
        return $this->numLicence;
    }

    public function setNumLicence(?string $numLicence): static
    {
        $this->numLicence = $numLicence;

        return $this;
    }

    public function getQualite(): ?string
    {
        return $this->qualite;
    }

    public function setQualite(?string $qualite): static
    {
        $this->qualite = $qualite;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->getNomPrenom();
    }
}

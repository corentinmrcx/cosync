<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\Civilite;
use App\Repository\ClubSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages du club, communs à toutes les saisons. Table à ligne unique : le club change de
 * banque, pas de RIB par saison.
 *
 * Les colonnes iban / bic / titulaire_compte de `season` ont porté ces valeurs jusqu'à
 * Version20260809160000, qui les recopie ici. Elles subsistent en base, dé-mappées, le temps
 * de valider la bascule en production.
 */
#[ORM\Entity(repositoryClass: ClubSettingsRepository::class)]
#[ORM\Table(name: 'club_settings')]
class ClubSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $titulaireCompte = null;

    /**
     * Lien de la boutique du club (HelloAsso). Réglage du club et non de la saison : la
     * boutique est une page de l'association, elle ne se recrée pas à chaque rentrée.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $boutiqueUrl = null;

    /**
     * Ouverture de la boutique, séparée du lien : le club lance ses licences d'abord et sa
     * boutique quelques jours plus tard. Le lien se prépare donc à froid, et rien n'est
     * annoncé — ni page, ni mail — tant que l'admin n'a pas ouvert.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $boutiqueOuverte = false;

    /* ── Identité de l'association ──
       Jusqu'ici écrite en dur dans une trentaine de templates. Elle vit ici parce qu'elle
       est ce qu'une attestation de paiement engage juridiquement, et parce qu'elle est le
       premier bloc à devoir changer si l'outil sert un jour un autre club. */

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $associationNom = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $associationAdresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $associationCodePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $associationVille = null;

    /** 14 chiffres, espaces de lisibilité tolérés — jamais deviné : un SIRET faux sur une attestation la disqualifie. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $associationSiret = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $associationEmail = null;

    #[ORM\Column(length: 10, nullable: true, enumType: Civilite::class)]
    private ?Civilite $signataireCivilite = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $signataireNom = null;

    /** Texte libre : « trésorière », « président », « secrétaire général »… aucune liste fermée ne tient. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $signataireQualite = null;

    /**
     * Nom du fichier de la signature scannée, rangée hors de `public/` : c'est un
     * paraphe, il ne doit pas être servi par le serveur web. Facultatif — sans lui,
     * l'attestation imprime un cadre vide à signer à la main.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureCachetFichier = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        // Un champ vidé arrive en chaîne vide : la stocker laisserait accepteVirement()
        // répondre vrai et le formulaire public proposerait un virement sans IBAN.
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

    public function getBoutiqueUrl(): ?string
    {
        return $this->boutiqueUrl;
    }

    public function setBoutiqueUrl(?string $boutiqueUrl): static
    {
        $this->boutiqueUrl = $this->normaliser($boutiqueUrl);

        return $this;
    }

    /** Sans IBAN, l'option « virement » disparaît du formulaire public. */
    public function accepteVirement(): bool
    {
        return $this->iban !== null;
    }

    public function isBoutiqueOuverte(): bool
    {
        return $this->boutiqueOuverte;
    }

    public function setBoutiqueOuverte(bool $boutiqueOuverte): static
    {
        $this->boutiqueOuverte = $boutiqueOuverte;

        return $this;
    }

    /**
     * Le lien tel qu'il doit être vu du dehors : null tant que la boutique n'est pas
     * ouverte. Les écrans publics et les mails passent par ici, jamais par
     * {@see getBoutiqueUrl()} — qui, lui, sert au formulaire d'administration à relire
     * un lien préparé mais pas encore annoncé.
     */
    public function getBoutiqueUrlPublique(): ?string
    {
        return $this->aBoutique() ? $this->boutiqueUrl : null;
    }

    /** Sans lien renseigné ni ouverture, la boutique n'est annoncée nulle part — ni page, ni mail. */
    public function aBoutique(): bool
    {
        return $this->boutiqueUrl !== null && $this->boutiqueOuverte;
    }

    /** Un lien est prêt : l'ouverture n'attend plus qu'une décision de l'admin. */
    public function boutiqueOuvrable(): bool
    {
        return $this->boutiqueUrl !== null && !$this->boutiqueOuverte;
    }

    public function getAssociationNom(): ?string
    {
        return $this->associationNom;
    }

    public function setAssociationNom(?string $associationNom): static
    {
        $this->associationNom = $this->normaliser($associationNom);

        return $this;
    }

    public function getAssociationAdresse(): ?string
    {
        return $this->associationAdresse;
    }

    public function setAssociationAdresse(?string $associationAdresse): static
    {
        $this->associationAdresse = $this->normaliser($associationAdresse);

        return $this;
    }

    public function getAssociationCodePostal(): ?string
    {
        return $this->associationCodePostal;
    }

    public function setAssociationCodePostal(?string $associationCodePostal): static
    {
        $this->associationCodePostal = $this->normaliser($associationCodePostal);

        return $this;
    }

    public function getAssociationVille(): ?string
    {
        return $this->associationVille;
    }

    public function setAssociationVille(?string $associationVille): static
    {
        $this->associationVille = $this->normaliser($associationVille);

        return $this;
    }

    public function getAssociationSiret(): ?string
    {
        return $this->associationSiret;
    }

    public function setAssociationSiret(?string $associationSiret): static
    {
        $this->associationSiret = $this->normaliser($associationSiret);

        return $this;
    }

    public function getAssociationEmail(): ?string
    {
        return $this->associationEmail;
    }

    public function setAssociationEmail(?string $associationEmail): static
    {
        $this->associationEmail = $this->normaliser($associationEmail);

        return $this;
    }

    public function getSignataireCivilite(): ?Civilite
    {
        return $this->signataireCivilite;
    }

    public function setSignataireCivilite(?Civilite $signataireCivilite): static
    {
        $this->signataireCivilite = $signataireCivilite;

        return $this;
    }

    public function getSignataireNom(): ?string
    {
        return $this->signataireNom;
    }

    public function setSignataireNom(?string $signataireNom): static
    {
        $this->signataireNom = $this->normaliser($signataireNom);

        return $this;
    }

    public function getSignataireQualite(): ?string
    {
        return $this->signataireQualite;
    }

    public function setSignataireQualite(?string $signataireQualite): static
    {
        $this->signataireQualite = $this->normaliser($signataireQualite);

        return $this;
    }

    public function getSignatureCachetFichier(): ?string
    {
        return $this->signatureCachetFichier;
    }

    public function setSignatureCachetFichier(?string $signatureCachetFichier): static
    {
        $this->signatureCachetFichier = $this->normaliser($signatureCachetFichier);

        return $this;
    }

    /**
     * « 51320 Soudron » — la ligne telle qu'elle s'imprime sous l'adresse.
     * Rend null plutôt qu'une ligne à moitié vide quand rien n'est renseigné.
     */
    public function getAssociationVilleComplete(): ?string
    {
        $ligne = trim(($this->associationCodePostal ?? '') . ' ' . ($this->associationVille ?? ''));

        return $ligne === '' ? null : $ligne;
    }

    /**
     * Le club peut-il émettre une attestation de paiement ?
     *
     * Sans identité d'association ni signataire nommé, le document n'engagerait
     * personne : mieux vaut un écran qui renvoie vers la configuration qu'une
     * attestation qu'un employeur refusera.
     */
    public function peutAttesterUnPaiement(): bool
    {
        return $this->associationNom !== null
            && $this->signataireNom !== null
            && $this->signataireQualite !== null
            && $this->signataireCivilite !== null;
    }

    private function normaliser(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}

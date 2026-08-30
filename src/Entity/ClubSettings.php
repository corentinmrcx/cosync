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

    /* ── Relance automatique des licences non soldées ──
       Trois réglages qui vont ensemble : l'interrupteur, le délai, le plafond. Ils vivent
       au niveau du club et non de la saison — c'est une politique de relance, elle ne se
       redécide pas chaque rentrée. */

    /**
     * Interrupteur du robot de relance, **éteint par défaut**.
     *
     * On n'allume pas sans décision explicite un automate qui écrit à tout un effectif ;
     * et il faut pouvoir l'éteindre d'un clic si un envoi part de travers.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $relanceActive = false;

    /**
     * Jours à laisser passer **depuis le dernier mail reçu par la personne**, quel qu'il
     * soit — pas depuis son inscription. C'est ce qui fait qu'une relance passée à la main
     * repousse mécaniquement celle du robot, au lieu de la doubler quelques heures après.
     */
    #[ORM\Column(options: ['default' => 10])]
    private int $relanceDelaiJours = 10;

    /**
     * Nombre maximum de relances automatiques par personne et par saison.
     *
     * Sans plafond, le robot écrirait tous les dix jours jusqu'en juin à quelqu'un qui ne
     * paiera pas. Au-delà, la relance n'est plus un mail : c'est un coup de téléphone.
     */
    #[ORM\Column(options: ['default' => 3])]
    private int $relanceMax = 3;

    /* ── Planning des matchs : rattachement au calendrier FFF ──
       Au niveau du club et non de la saison : le numéro d'un club à la FFF ne change pas
       à la rentrée. Quand un troisième outil aura besoin de réglages, il faudra les
       sortir d'ici — ClubSettings ne doit pas devenir le fourre-tout de tous les outils. */

    /**
     * Numéro du club à la FFF (`cl_no` de l'API DOFA), qui n'est **pas** son numéro
     * d'affiliation : c'est `cl_no` que `/api/clubs/{n}` attend. Null tant que le club
     * n'a pas été rattaché — le planning se saisit alors entièrement à la main.
     */
    #[ORM\Column(nullable: true)]
    private ?int $fffClubNo = null;

    /**
     * Synchronisation quotidienne du calendrier, **éteinte par défaut**.
     *
     * Même doctrine que `relanceActive` : la migration installe l'automate, elle ne
     * l'allume pas. On l'active après une synchronisation manuelle qui a montré ce
     * qu'elle ramène — d'autant que l'accès à l'API depuis le serveur n'est pas garanti.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fffSyncActive = false;

    /**
     * Les personnes à joindre au sujet des matchs, imprimées au pied du flyer distribué
     * dans les boîtes aux lettres — une par ligne, « Nom — téléphone ».
     *
     * Texte libre et non une liste de `Dirigeant` : ce ne sont pas des rôles du club mais
     * les deux ou trois personnes qui acceptent que leur numéro parte dans tout le
     * village. Aucun rôle ne dit ça, et l'y déduire ferait publier un numéro personnel
     * sans que personne l'ait décidé.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $planningContacts = null;

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

    public function isRelanceActive(): bool
    {
        return $this->relanceActive;
    }

    public function setRelanceActive(bool $relanceActive): static
    {
        $this->relanceActive = $relanceActive;

        return $this;
    }

    public function getRelanceDelaiJours(): int
    {
        return $this->relanceDelaiJours;
    }

    public function setRelanceDelaiJours(int $relanceDelaiJours): static
    {
        $this->relanceDelaiJours = $relanceDelaiJours;

        return $this;
    }

    public function getRelanceMax(): int
    {
        return $this->relanceMax;
    }

    public function setRelanceMax(int $relanceMax): static
    {
        $this->relanceMax = $relanceMax;

        return $this;
    }

    public function getFffClubNo(): ?int
    {
        return $this->fffClubNo;
    }

    public function setFffClubNo(?int $fffClubNo): static
    {
        $this->fffClubNo = $fffClubNo;

        return $this;
    }

    public function isFffSyncActive(): bool
    {
        return $this->fffSyncActive;
    }

    public function setFffSyncActive(bool $fffSyncActive): static
    {
        $this->fffSyncActive = $fffSyncActive;

        return $this;
    }

    /** Le club est rattaché à la FFF : la synchronisation a quelque chose à interroger. */
    public function estRattacheALaFff(): bool
    {
        return $this->fffClubNo !== null;
    }

    public function getPlanningContacts(): ?string
    {
        return $this->planningContacts;
    }

    public function setPlanningContacts(?string $planningContacts): static
    {
        $this->planningContacts = $this->normaliser($planningContacts);

        return $this;
    }

    /**
     * Les contacts du flyer, une entrée par ligne non vide.
     *
     * @return list<string>
     */
    public function getPlanningContactsLignes(): array
    {
        if ($this->planningContacts === null) {
            return [];
        }

        $lignes = preg_split('/\r\n|\r|\n/', $this->planningContacts) ?: [];

        return array_values(array_filter(array_map('trim', $lignes), static fn (string $l) => $l !== ''));
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

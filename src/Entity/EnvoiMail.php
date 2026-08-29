<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Repository\EnvoiMailRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un mail réellement parti du club. Journal **append-only** : une ligne par envoi.
 *
 * Il existe parce que `Licencie::$linkSentAt` ne peut pas répondre à la question posée.
 * Cette colonne est écrasée à chaque renvoi : elle atteste que la personne a été contactée
 * un jour, jamais combien de fois ni quand. Résultat, une relance ne se voyait nulle part —
 * et un admin pouvait relancer à la main quelqu'un que le club venait d'écrire.
 *
 * Trois règles tiennent la classe :
 *
 * - **Une seule plume.** C'est `ClubMailer::envoyer()` qui écrit ici, jamais les services
 *   appelants. Le traçage dupliqué finit par diverger, et c'est le côté oublié qui
 *   enverrait le mail invisible — le défaut exact qu'on corrige.
 * - **Après l'envoi, jamais avant.** Une ligne écrite sur un envoi qui a échoué ferait
 *   croire la personne relancée et empêcherait la vraie relance de partir.
 * - **L'adresse journalisée est celle réellement visée**, pas la redirection du mode bêta :
 *   un test ne doit pas laisser croire qu'on a écrit au développeur.
 *
 * Pas de `season` : `Licencie` et `Dirigeant` sont déjà cloisonnés par saison, toute
 * question sur les envois d'une saison passe donc par eux. Seul un `Detenteur` vit hors
 * saison — délibérément (cf. §4) — et une colonne saison serait le seul endroit à
 * prétendre le contraire.
 */
#[ORM\Entity(repositoryClass: EnvoiMailRepository::class)]
#[ORM\Index(name: 'idx_envoi_mail_licencie', columns: ['licencie_uuid', 'sent_at'])]
#[ORM\Index(name: 'idx_envoi_mail_dirigeant', columns: ['dirigeant_uuid', 'sent_at'])]
#[ORM\Index(name: 'idx_envoi_mail_detenteur', columns: ['detenteur_id', 'sent_at'])]
class EnvoiMail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 40, enumType: TypeMail::class)]
    private TypeMail $type;

    #[ORM\Column(length: 20, enumType: OrigineEnvoi::class)]
    private OrigineEnvoi $origine;

    /** L'adresse visée, telle qu'elle l'était hors mode bêta. */
    #[ORM\Column(length: 180)]
    private string $destinataireEmail;

    /**
     * Les trois rattachements possibles, exclusifs et tous facultatifs.
     *
     * CASCADE : une fiche ne se supprime que si rien ne s'y est passé — et
     * `SuppressionFicheService` compte désormais un mail parti parmi ces empêchements.
     * La cascade ne se déclenche donc en pratique jamais ; elle est là pour qu'un
     * rattrapage en base ne laisse pas de ligne orpheline.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'licencie_uuid', referencedColumnName: 'uuid', nullable: true, onDelete: 'CASCADE')]
    private ?Licencie $licencie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'dirigeant_uuid', referencedColumnName: 'uuid', nullable: true, onDelete: 'CASCADE')]
    private ?Dirigeant $dirigeant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Detenteur $detenteur = null;

    /** L'admin à l'origine du geste. Null pour un envoi automatique ou hors requête. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $declenchePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(TypeMail $type, OrigineEnvoi $origine, string $destinataireEmail)
    {
        $this->type = $type;
        $this->origine = $origine;
        $this->destinataireEmail = $destinataireEmail;
        $this->sentAt = new \DateTimeImmutable();
    }

    /** Range l'envoi sous la bonne fiche, sans que l'appelant ait à connaître les trois colonnes. */
    public function rattacherA(Licencie|Dirigeant|Detenteur|null $personne): static
    {
        match (true) {
            $personne instanceof Licencie => $this->licencie = $personne,
            $personne instanceof Dirigeant => $this->dirigeant = $personne,
            $personne instanceof Detenteur => $this->detenteur = $personne,
            default => null,
        };

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): TypeMail
    {
        return $this->type;
    }

    public function getOrigine(): OrigineEnvoi
    {
        return $this->origine;
    }

    public function getDestinataireEmail(): string
    {
        return $this->destinataireEmail;
    }

    public function getLicencie(): ?Licencie
    {
        return $this->licencie;
    }

    public function getDirigeant(): ?Dirigeant
    {
        return $this->dirigeant;
    }

    public function getDetenteur(): ?Detenteur
    {
        return $this->detenteur;
    }

    public function getDeclenchePar(): ?User
    {
        return $this->declenchePar;
    }

    public function setDeclenchePar(?User $declenchePar): static
    {
        $this->declenchePar = $declenchePar;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    /**
     * Rejoue un envoi passé, pour le seul backfill de migration : l'historique des liens
     * déjà partis vit dans `linkSentAt`, et il ne doit pas disparaître de l'affichage.
     */
    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    /** L'auteur affiché dans l'historique : l'admin s'il est connu, l'origine sinon. */
    public function auteur(): string
    {
        return $this->declenchePar?->getEmail() ?? $this->origine->label();
    }
}

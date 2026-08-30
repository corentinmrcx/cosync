<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\MatchSource;
use App\Repository\MatchDomicileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une rencontre jouée **sur le terrain du club**, telle qu'elle sera imprimée.
 *
 * Ce n'est pas un miroir du calendrier fédéral : c'est le planning que le club distribue.
 * D'où deux sources qui cohabitent (cf. MatchSource) — le flux FFF pour les compétitions
 * engagées, la saisie du club pour les plateaux U7/U9, que la FFF ne publie pas.
 *
 * **Qui possède quoi.** Sur une ligne venue de la FFF, le district fait foi : date, heure,
 * catégorie et adversaire sont réécrits à chaque synchronisation. Le club possède la `note`
 * et le `masque`. Un horaire fédéral faux se corrige en **détachant** la ligne
 * (`detacherDeLaFff()`), jamais en éditant par-dessus : sans cette sortie explicite, la
 * correction serait effacée à la synchronisation suivante sans que personne le voie.
 *
 * `fffMaNo` est conservé même après détachement — c'est lui qui empêche la synchronisation
 * de recréer le match en double.
 */
#[ORM\Entity(repositoryClass: MatchDomicileRepository::class)]
#[ORM\Table(name: 'match_domicile')]
#[ORM\Index(name: 'idx_match_domicile_season_date', columns: ['season_id', 'date'])]
#[ORM\UniqueConstraint(name: 'uniq_match_domicile_fff', columns: ['season_id', 'fff_ma_no'])]
class MatchDomicile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Season $season;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /**
     * `HH:MM`, ou null quand l'horaire n'est pas encore fixé.
     *
     * Une chaîne et non un `time` : l'heure est un libellé qu'on imprime, jamais un
     * instant qu'on calcule. La stocker en `DateTimeImmutable` ferait entrer un fuseau
     * horaire dans un document papier — et un match de 15h00 s'imprimerait à 14h00.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heure = null;

    /** « Séniors D3 », « U16 », « Plateau U7 » — le libellé lu par l'habitant, pas un code FFF. */
    #[ORM\Column(length: 60)]
    private string $categorie;

    /** Null pour un plateau, et pour une équipe exempte côté FFF (`away` y vaut null). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $adversaire = null;

    /** La seule zone de texte que le club garde sur une ligne fédérale. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 10, enumType: MatchSource::class)]
    private MatchSource $source = MatchSource::MANUEL;

    /** Identifiant du match à la FFF (`ma_no`) : clé d'upsert de la synchronisation. */
    #[ORM\Column(nullable: true)]
    private ?int $fffMaNo = null;

    /** « SENIORS DISTRICT 3 » — informatif, affiché en admin, jamais sur le flyer. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fffCompetition = null;

    /**
     * Terrain annoncé par la FFF. Nullable même sur un match fédéral : le district
     * l'affecte parfois après la publication du calendrier.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fffTerrain = null;

    /**
     * Retiré des documents sans être supprimé.
     *
     * Supprimer une ligne fédérale ne servirait à rien : la synchronisation suivante la
     * recréerait. Le masque est la seule façon d'écarter durablement un match du planning
     * distribué — un huis clos, un match délocalisé — tout en gardant sa trace.
     */
    #[ORM\Column]
    private bool $masque = false;

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

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function setSeason(Season $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getHeure(): ?string
    {
        return $this->heure;
    }

    public function setHeure(?string $heure): static
    {
        $this->heure = $heure;

        return $this;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getAdversaire(): ?string
    {
        return $this->adversaire;
    }

    public function setAdversaire(?string $adversaire): static
    {
        $this->adversaire = $adversaire;

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

    public function getSource(): MatchSource
    {
        return $this->source;
    }

    public function setSource(MatchSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getFffMaNo(): ?int
    {
        return $this->fffMaNo;
    }

    public function setFffMaNo(?int $fffMaNo): static
    {
        $this->fffMaNo = $fffMaNo;

        return $this;
    }

    public function getFffCompetition(): ?string
    {
        return $this->fffCompetition;
    }

    public function setFffCompetition(?string $fffCompetition): static
    {
        $this->fffCompetition = $fffCompetition;

        return $this;
    }

    public function getFffTerrain(): ?string
    {
        return $this->fffTerrain;
    }

    public function setFffTerrain(?string $fffTerrain): static
    {
        $this->fffTerrain = $fffTerrain;

        return $this;
    }

    public function isMasque(): bool
    {
        return $this->masque;
    }

    public function setMasque(bool $masque): static
    {
        $this->masque = $masque;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /* ── Règles portées par l'entité ── */

    /** Vrai si la synchronisation FFF réécrit cette ligne à chaque passage. */
    public function suitLaFff(): bool
    {
        return $this->source->suitLaFff() && $this->fffMaNo !== null;
    }

    /** Vrai si l'admin peut corriger date, heure, catégorie et adversaire à la main. */
    public function estModifiable(): bool
    {
        return !$this->suitLaFff();
    }

    /**
     * Rend la ligne au club. Le `fffMaNo` reste : c'est ce qui empêche la synchronisation
     * suivante de recréer le match une seconde fois.
     */
    public function detacherDeLaFff(): static
    {
        $this->source = MatchSource::MANUEL;

        return $this;
    }

    /** Repasse la ligne sous l'autorité du district — la prochaine sync la réécrira. */
    public function reprendreLaFff(): static
    {
        if ($this->fffMaNo !== null) {
            $this->source = MatchSource::FFF;
        }

        return $this;
    }

    /**
     * Vrai si le club n'a rien posé sur cette ligne.
     *
     * Une ligne intacte disparue du flux fédéral peut être supprimée sans rien perdre ;
     * une ligne annotée ou masquée porte un travail que la sync n'a pas le droit d'effacer.
     */
    public function estIntacte(): bool
    {
        return !$this->masque && ($this->note === null || $this->note === '');
    }

    /**
     * Vrai si la ligne est un plateau : le club accueille plusieurs équipes au lieu d'en
     * affronter une.
     *
     * Décidé sur le libellé de catégorie, que le club écrit « Plateau U7 », « Plateau U9 ».
     * C'est une heuristique sur du texte libre, assumée : un plateau n'existe pas au
     * calendrier fédéral — il est toujours saisi à la main — et lui donner une colonne
     * dédiée demanderait de la remplir à chaque match, y compris pour les 90 % de lignes
     * qui n'en sont pas.
     */
    public function estPlateau(): bool
    {
        return stripos($this->categorie, 'plateau') !== false;
    }

    /**
     * La phrase imprimée en face de la catégorie sur le flyer : « Contre Sept Saulx »,
     * « Accueil de Châlons FCO, Fagnières et Côte des Noirs ».
     *
     * Le club saisit la liste des invités dans `adversaire` pour un plateau ; c'est le
     * document qui met le mot devant, pas lui.
     */
    public function libelleRencontre(): string
    {
        if ($this->adversaire === null || $this->adversaire === '') {
            return $this->estPlateau() ? 'Plateau' : 'Adversaire à confirmer';
        }

        return ($this->estPlateau() ? 'Accueil de ' : 'Contre ') . $this->adversaire;
    }

    /** Clé de tri : la date d'abord, l'heure ensuite. Une heure absente passe en dernier. */
    public function cleDeTri(): string
    {
        return $this->date->format('Y-m-d') . ' ' . ($this->heure ?? '99:99');
    }
}

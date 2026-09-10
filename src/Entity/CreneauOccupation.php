<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\JourSemaine;
use App\Enum\UsageOccupation;
use App\Repository\CreneauOccupationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un créneau **type** d'occupation d'un terrain : « le mercredi, de 14h00 à 15h30, les U11
 * sur la moitié 1 du terrain d'honneur ».
 *
 * Ce n'est pas une séance. Il n'y a ni date, ni annulation, ni report : la grille dit ce qui
 * est réservé, sauf changement, pour toute la saison. C'est le document que la mairie
 * attend — le créneau séniors du dimanche après-midi reste réservé les week-ends sans
 * match, sinon la commune croirait le terrain libre.
 *
 * **Ce que le temps n'entre pas ici.** Pas de période de validité : la grille en base est
 * l'état courant, et le PDF archivé sur le Drive est la trace de ce qui a été transmis. Si
 * les U16 passent au mardi en janvier, on corrige le créneau et on réédite. Un
 * `valableDu`/`valableAu` par ligne ferait de cette grille un agenda par la porte de
 * derrière, alors que tout son intérêt est de n'en pas être un.
 *
 * **Les chevauchements sont normaux** et ne sont ni refusés ni signalés : deux catégories
 * partagent réellement un terrain, et la grille les pose côte à côte dans leur colonne.
 */
#[ORM\Entity(repositoryClass: CreneauOccupationRepository::class)]
#[ORM\Table(name: 'creneau_occupation')]
#[ORM\Index(name: 'idx_creneau_occupation_season', columns: ['season_id'])]
class CreneauOccupation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Season $season;

    #[ORM\Column(length: 10, enumType: JourSemaine::class)]
    private JourSemaine $jour;

    /**
     * `HH:MM`, accroché au quart d'heure.
     *
     * Une chaîne et non un `time`, pour la raison déjà écrite sur {@see MatchDomicile::$heure} :
     * c'est un libellé qu'on imprime, jamais un instant qu'on calcule. Un
     * `DateTimeImmutable` ferait entrer un fuseau horaire dans un document papier, et un
     * créneau de 18h00 s'imprimerait à 17h00.
     */
    #[ORM\Column(length: 5)]
    private string $heureDebut;

    #[ORM\Column(length: 5)]
    private string $heureFin;

    /**
     * Obligatoire à la saisie — le sélecteur ne propose que les équipes de la saison, il n'y
     * a aucune zone de texte libre.
     *
     * Nullable en base tout de même, et en `SET NULL` : une équipe supprimée en cours de
     * saison ne doit pas emporter le créneau en silence ni faire échouer sa suppression
     * depuis un autre écran. Le créneau reste, sans équipe, et la grille le signale pour
     * qu'on le complète. C'est aussi l'état d'un créneau repris d'une saison précédente
     * dont l'équipe n'a pas d'équivalent dans la nouvelle.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $equipe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private EspaceTerrain $espace;

    #[ORM\Column(length: 15, enumType: UsageOccupation::class)]
    private UsageOccupation $usage = UsageOccupation::ENTRAINEMENT;

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

    public function getJour(): JourSemaine
    {
        return $this->jour;
    }

    public function setJour(JourSemaine $jour): static
    {
        $this->jour = $jour;

        return $this;
    }

    public function getHeureDebut(): string
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(string $heureDebut): static
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): string
    {
        return $this->heureFin;
    }

    public function setHeureFin(string $heureFin): static
    {
        $this->heureFin = $heureFin;

        return $this;
    }

    public function getEquipe(): ?Team
    {
        return $this->equipe;
    }

    public function setEquipe(?Team $equipe): static
    {
        $this->equipe = $equipe;

        return $this;
    }

    public function getEspace(): EspaceTerrain
    {
        return $this->espace;
    }

    public function setEspace(EspaceTerrain $espace): static
    {
        $this->espace = $espace;

        return $this;
    }

    public function getUsage(): UsageOccupation
    {
        return $this->usage;
    }

    public function setUsage(UsageOccupation $usage): static
    {
        $this->usage = $usage;

        return $this;
    }

    /* ── Dérivés ── */

    /** Minutes depuis minuit — l'unité dans laquelle la grille se calcule. */
    public function minutesDebut(): int
    {
        return self::enMinutes($this->heureDebut);
    }

    public function minutesFin(): int
    {
        return self::enMinutes($this->heureFin);
    }

    public function duree(): int
    {
        return $this->minutesFin() - $this->minutesDebut();
    }

    /** « 18h00 – 19h30 », tel qu'il s'écrit dans le bloc. */
    public function libelleHoraire(): string
    {
        return sprintf(
            '%s – %s',
            str_replace(':', 'h', $this->heureDebut),
            str_replace(':', 'h', $this->heureFin),
        );
    }

    /** L'équipe, ou la mention d'un créneau à compléter — jamais une case vide. */
    public function libelleEquipe(): string
    {
        return $this->equipe?->getName() ?? 'Équipe à compléter';
    }

    /** Le jour puis l'heure : l'ordre de lecture d'une colonne. */
    public function cleDeTri(): string
    {
        return sprintf('%d-%s-%s', $this->jour->numero(), $this->heureDebut, $this->heureFin);
    }

    public static function enMinutes(string $heure): int
    {
        [$heures, $minutes] = array_map('intval', explode(':', $heure) + [1 => '0']);

        return $heures * 60 + $minutes;
    }
}

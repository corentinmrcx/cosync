<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\EspaceTerrainRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un terrain du complexe, tel qu'on le désigne quand on réserve un créneau.
 *
 * **Hors saison**, comme le détenteur d'une clé : un terrain ne change pas de nom au
 * 1ᵉʳ juillet. C'est ce qui permet à la grille d'une saison de se reprendre telle quelle
 * sur la suivante.
 *
 * La liste est **plate**, et le terrain d'honneur divisible y entre trois fois : entier, et
 * chacune de ses deux moitiés de foot à 8. Une hiérarchie parent/enfant ne servirait qu'à
 * détecter un conflit — or on n'en détecte aucun, deux créneaux au même horaire étant un
 * cas courant. Ce que « moitié 1 » veut dire, c'est le lecteur du document qui le sait, pas
 * la base.
 */
#[ORM\Entity(repositoryClass: EspaceTerrainRepository::class)]
#[ORM\Table(name: 'espace_terrain')]
#[ORM\UniqueConstraint(name: 'uniq_espace_terrain_nom', columns: ['nom'])]
class EspaceTerrain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** « Terrain d'honneur », « Terrain d'entraînement 1 » — le nom lu sur le document. */
    #[ORM\Column(length: 80)]
    private string $nom;

    /** Rang d'affichage dans les sélecteurs et la légende, décidé par le club. */
    #[ORM\Column]
    private int $ordre = 0;

    /**
     * Un terrain retiré du service sort des sélecteurs sans être supprimé : les créneaux
     * des saisons passées continuent de le nommer. C'est la même sortie que pour un article
     * de stock archivé.
     */
    #[ORM\Column]
    private bool $actif = true;

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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }
}

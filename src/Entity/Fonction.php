<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\FonctionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une fonction du club telle qu'elle se déclare à un tiers — la mairie, le conseil
 * d'administration. Référentiel de club, hors saison, réglé depuis /admin/club/fonctions.
 *
 * À ne pas confondre avec {@see \App\Enum\DirigeantRole}, qui dit ce que l'application
 * **doit** à la personne (un kit de dotation, une charte à signer) et se compte sur trois
 * doigts. La fonction, elle, dit ce que la personne **fait**, et n'entraîne rien. Le rôle
 * groupe, la fonction nomme : les confondre a produit un récapitulatif où quatre personnes
 * étaient « Responsable foot » alors que le club n'en a qu'un.
 *
 * Une personne en porte autant qu'il en faut — « En charge de l'école de foot » *et*
 * « Contribue à l'organisation du foot » : c'est exactement ce qu'un libellé unique ne
 * savait pas dire.
 *
 * `porteEquipe` évite d'entrer « Entraîneur des U16 », « Entraîneur des U11 », « Entraîneur
 * des séniors » : le libellé est suivi de l'équipe de la fiche, et suit donc le dirigeant
 * qui change d'équipe l'année suivante.
 */
#[ORM\Entity(repositoryClass: FonctionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fonction_libelle', columns: ['libelle'])]
class Fonction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 120)]
    private string $libelle;

    /** Le libellé est suivi du nom de l'équipe de la fiche : « Entraîneur » + « U16 ». */
    #[ORM\Column(options: ['default' => false])]
    private bool $porteEquipe = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getId(): int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function isPorteEquipe(): bool
    {
        return $this->porteEquipe;
    }

    public function setPorteEquipe(bool $porteEquipe): static
    {
        $this->porteEquipe = $porteEquipe;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}

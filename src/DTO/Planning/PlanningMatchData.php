<?php declare(strict_types=1);

namespace App\DTO\Planning;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie d'un match à la main : le formulaire de la liste et celui de modification.
 *
 * Mutable et non `readonly` : c'est un objet de formulaire Symfony, qui l'hydrate
 * champ par champ.
 */
final class PlanningMatchData
{
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    public ?\DateTimeImmutable $date = null;

    /**
     * `HH:MM`, rempli par le sélecteur d'heure du navigateur. Facultatif — un plateau
     * annoncé « le matin » n'a pas encore d'horaire, et refuser la ligne ferait ressaisir
     * tout le match une fois l'heure connue.
     */
    public ?string $heure = null;

    #[Assert\NotBlank(message: 'La catégorie est obligatoire.')]
    #[Assert\Length(max: 60)]
    public ?string $categorie = null;

    #[Assert\Length(max: 100)]
    public ?string $adversaire = null;

    #[Assert\Length(max: 255)]
    public ?string $note = null;

    public static function depuis(?\DateTimeImmutable $date, ?string $heure, ?string $categorie, ?string $adversaire, ?string $note): self
    {
        $data = new self();
        $data->date = $date;
        $data->heure = $heure;
        $data->categorie = $categorie;
        $data->adversaire = $adversaire;
        $data->note = $note;

        return $data;
    }
}

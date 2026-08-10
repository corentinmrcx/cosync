<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use Symfony\Component\Validator\Constraints as Assert;

final class DirigeantData
{
    public ?string $nom = null;
    public ?string $prenom = null;

    #[Assert\Email(message: 'Cet email n\'est pas valide.')]
    public ?string $email = null;

    #[Assert\Regex(
        pattern: '/^(?:(?:\+|00)33[\s.\-]?|0)[1-9](?:[\s.\-]?\d{2}){4}$/',
        message: 'Numéro de téléphone invalide (ex : 06 12 34 56 78).'
    )]
    public ?string $telephone = null;
    public ?\DateTimeImmutable $dateNaissance = null;
    /** Nullable pour que le form puisse porter une valeur hors enum et la rejeter proprement. */
    #[Assert\NotNull(message: 'Le rôle est requis.')]
    public ?DirigeantRole $role = DirigeantRole::DIRIGEANT;
    public ?string $tailleHaut = null;
    public ?string $tailleBas = null;
    public ?string $pointure = null;
    public ?Team $team = null;
    public ?string $numLicence = null;
    public ?Licencie $licencie = null;
}

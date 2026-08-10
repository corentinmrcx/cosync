<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Category;
use App\Entity\Team;
use App\Enum\NatureLicence;
use Symfony\Component\Validator\Constraints as Assert;

final class LicencieCreateData
{
    public ?string $nom = null;
    public ?string $prenom = null;
    public ?\DateTimeImmutable $dateNaissance = null;
    public ?Category $category = null;
    public ?Team $team = null;

    #[Assert\Email(message: 'Cet email n\'est pas valide.')]
    public ?string $email = null;

    #[Assert\Regex(
        pattern: '/^(?:(?:\+|00)33[\s.\-]?|0)[1-9](?:[\s.\-]?\d{2}){4}$/',
        message: 'Numéro de téléphone invalide (ex : 06 12 34 56 78).'
    )]
    public ?string $telephone = null;

    public ?string $voieRue = null;
    public ?string $codePostal = null;
    public ?string $ville = null;
    public ?string $numLicence = null;

    /** Un licencié saisi à la main est presque toujours une première licence au club. */
    public ?NatureLicence $natureLicence = NatureLicence::NOUVELLE_DEMANDE;
}

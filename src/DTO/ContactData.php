<?php declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Correction à la main des coordonnées, indépendamment de l'identité FFF :
 * l'admin ne touche ni au nom, ni à la date de naissance, ni au numéro de licence,
 * qui restent la propriété de FootClubs.
 */
final class ContactData
{
    #[Assert\Email(message: 'Cet email n\'est pas valide.')]
    public ?string $email = null;

    #[Assert\Regex(
        pattern: '/^(?:(?:\+|00)33[\s.\-]?|0)[1-9](?:[\s.\-]?\d{2}){4}$/',
        message: 'Numéro de téléphone invalide (ex : 06 12 34 56 78).'
    )]
    public ?string $telephone = null;
}

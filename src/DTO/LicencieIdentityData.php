<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Category;

final class LicencieIdentityData
{
    public ?string $nom = null;
    public ?string $prenom = null;
    public ?\DateTimeImmutable $dateNaissance = null;
    public ?Category $category = null;
    public ?string $email = null;
    public ?string $telephone = null;
    public ?string $numLicence = null;
}

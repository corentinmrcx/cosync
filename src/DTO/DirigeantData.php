<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\DirigeantRole;
use App\Entity\Licencie;
use App\Entity\Team;

final class DirigeantData
{
    public ?string $nom = null;
    public ?string $prenom = null;
    public ?string $email = null;
    public ?string $telephone = null;
    public ?\DateTimeImmutable $dateNaissance = null;
    public ?DirigeantRole $role = null;
    public ?string $tailleHaut = null;
    public ?string $tailleBas = null;
    public ?string $pointure = null;
    public ?Team $team = null;
    public ?string $numLicence = null;
    public ?Licencie $licencie = null;
}

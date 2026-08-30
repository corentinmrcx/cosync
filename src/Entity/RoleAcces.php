<?php declare(strict_types=1);

namespace App\Entity;

use App\Enum\Permission;
use App\Repository\RoleAccesRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un paquet de permissions que le club compose lui-même — « Trésorière », « Intendant ».
 *
 * Le nom porte « Acces » pour ne pas se confondre avec {@see \App\Enum\DirigeantRole}, qui
 * décrit la fonction d'un dirigeant dans le club (président, éducateur) et n'a rien à voir
 * avec les droits dans l'application.
 *
 * Les permissions sont stockées en `json` et non dans une table de liaison : ce ne sont pas
 * des lignes de référentiel mais des valeurs d'enum, versionnées avec le code qui les
 * applique (cf. {@see Permission}). Une table `permission` en base recréerait exactement
 * l'illusion qu'on écarte — celle de droits qu'on pourrait inventer sans écrire de code.
 */
#[ORM\Entity(repositoryClass: RoleAccesRepository::class)]
#[ORM\Table(name: 'role_acces')]
class RoleAcces
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 60, unique: true)]
    private string $nom;

    /**
     * Pas de description : les permissions cochées disent déjà ce que le rôle fait, et mieux
     * qu'une phrase que personne ne relit quand les cases changent.
     *
     * @var list<string> valeurs de {@see Permission}
     */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    /**
     * Rôle livré par le seed. Renommable et modifiable — c'est un point de départ, pas un
     * dogme — mais **non supprimable** : c'est ce qui garantit qu'un club garde toujours de
     * quoi rouvrir un accès après une fausse manœuvre.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $systeme = false;

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

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    /** @return list<Permission> les valeurs inconnues du catalogue sont ignorées */
    public function getPermissions(): array
    {
        return Permission::depuisValeurs($this->permissions);
    }

    /** @return list<string> la donnée brute, telle qu'elle est stockée */
    public function getPermissionsBrutes(): array
    {
        return $this->permissions;
    }

    /** @param list<Permission> $permissions */
    public function setPermissions(array $permissions): static
    {
        $valeurs = array_map(static fn (Permission $p): string => $p->value, $permissions);
        $this->permissions = array_values(array_unique($valeurs));

        return $this;
    }

    public function a(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    public function estSysteme(): bool
    {
        return $this->systeme;
    }

    public function setSysteme(bool $systeme): static
    {
        $this->systeme = $systeme;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

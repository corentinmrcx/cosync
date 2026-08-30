<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un compte d'administration.
 *
 * ⚠️ **Deux notions de « rôle » cohabitent ici, et il ne faut pas les confondre.**
 *
 * - `$roles` (json) est le tableau de rôles de Symfony, exigé par {@see UserInterface}.
 *   Il ne porte que `ROLE_USER` et ne décide de rien : la règle `^/ → ROLE_USER` de
 *   `security.yaml` s'en sert seulement pour distinguer connecté d'anonyme.
 * - `$rolesAcces` porte les **droits réels** dans l'application, via
 *   {@see \App\Enum\Permission}. C'est celui-là qu'on lit pour savoir qui peut quoi.
 *
 * La colonne `roles` n'a pas été réutilisée : elle est exposée par une interface de Symfony,
 * et lui faire porter autre chose aurait mêlé le portail d'entrée aux droits métier.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Season $selectedSeason = null;

    /**
     * Les droits réels. Plusieurs rôles se cumulent : dans un club, la même personne est
     * souvent trésorière **et** éducatrice, et un rôle par combinaison de casquettes serait
     * ingérable.
     *
     * @var Collection<int, RoleAcces>
     */
    #[ORM\ManyToMany(targetEntity: RoleAcces::class)]
    #[ORM\JoinTable(name: 'user_role_acces')]
    private Collection $rolesAcces;

    /**
     * Passe-partout : obtient toute permission sans en porter aucune.
     *
     * C'est la sortie de secours qui empêche de se verrouiller dehors en décochant la
     * mauvaise case — un club sans accès à ses propres signatures n'a aucun recours.
     * {@see \App\Service\Compte\UserService} garantit qu'il en reste toujours au moins un.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $superAdmin = false;

    public function __construct()
    {
        $this->rolesAcces = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getSelectedSeason(): ?Season
    {
        return $this->selectedSeason;
    }

    public function setSelectedSeason(?Season $season): static
    {
        $this->selectedSeason = $season;

        return $this;
    }

    /** @return Collection<int, RoleAcces> */
    public function getRolesAcces(): Collection
    {
        return $this->rolesAcces;
    }

    public function ajouterRoleAcces(RoleAcces $role): static
    {
        if (!$this->rolesAcces->contains($role)) {
            $this->rolesAcces->add($role);
        }

        return $this;
    }

    public function retirerRoleAcces(RoleAcces $role): static
    {
        $this->rolesAcces->removeElement($role);

        return $this;
    }

    public function estSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function setSuperAdmin(bool $superAdmin): static
    {
        $this->superAdmin = $superAdmin;

        return $this;
    }

    public function eraseCredentials(): void {}
}

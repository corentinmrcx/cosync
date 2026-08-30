<?php declare(strict_types=1);

namespace App\Service\Compte;

use App\DTO\LigneUtilisateur;
use App\Entity\RoleAcces;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes administrateurs et règles qui les protègent.
 *
 * Le **super-admin** passe partout et ne se supprime pas : c'est la sortie de secours qui
 * empêche de fermer définitivement l'accès à l'application après une fausse manœuvre sur les
 * rôles. Il doit toujours en rester au moins un.
 *
 * ⚠️ Ce statut était auparavant *déduit* de l'email de redirection du mode bêta : un réglage
 * d'exploitation décidait donc de qui administrait l'application, et changer cet email
 * déplaçait le super-admin sans que personne ne l'ait voulu. C'est désormais un fait porté
 * par le compte lui-même (`User.superAdmin`). Ne pas revenir à une dérivation.
 */
final class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $users,
    ) {}

    /** @param list<RoleAcces> $roles */
    public function creer(User $user, string $motDePasse, array $roles = []): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $motDePasse));
        $this->remplacerRoles($user, $roles);

        $this->em->persist($user);
        $this->em->flush();
    }

    /** Un mot de passe vide signifie « inchangé », pas « effacé ». */
    public function mettreAJour(User $user, ?string $motDePasse = null): void
    {
        if ($motDePasse !== null && $motDePasse !== '') {
            $user->setPassword($this->hasher->hashPassword($user, $motDePasse));
        }

        $this->em->flush();
    }

    /**
     * Remplace l'ensemble des rôles d'un compte.
     *
     * Passe par le retrait explicite plutôt que par une nouvelle collection : Doctrine ne
     * suit que les mutations de celle qu'il a chargée.
     *
     * @param list<RoleAcces> $roles
     */
    public function remplacerRoles(User $user, array $roles): void
    {
        foreach ($user->getRolesAcces()->toArray() as $existant) {
            $user->retirerRoleAcces($existant);
        }

        foreach ($roles as $role) {
            $user->ajouterRoleAcces($role);
        }
    }

    /** @throws \DomainException si la suppression fermerait l'accès à l'application */
    public function supprimer(User $user, User $auteur): void
    {
        if ($auteur->getId() === $user->getId()) {
            throw new \DomainException('Vous ne pouvez pas supprimer votre propre compte.');
        }

        if ($this->estSuperAdmin($user)) {
            throw new \DomainException('Le compte super-admin ne peut pas être supprimé.');
        }

        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * Accorde ou retire le statut de super-admin.
     *
     * @throws \DomainException si le retrait laisserait l'application sans aucun super-admin
     */
    public function definirSuperAdmin(User $user, bool $valeur): void
    {
        if (!$valeur && $this->estSuperAdmin($user) && $this->compterSuperAdmins() <= 1) {
            throw new \DomainException('Il doit rester au moins un super-admin : désignez-en un autre avant de retirer celui-ci.');
        }

        $user->setSuperAdmin($valeur);
        $this->em->flush();
    }

    /**
     * Liste des comptes avec les actions réellement permises sur chacun.
     *
     * @param User[] $users
     *
     * @return list<LigneUtilisateur>
     */
    public function lignes(array $users, ?User $auteur): array
    {
        $dernierSuperAdmin = $this->compterSuperAdmins() <= 1;

        return array_map(function (User $user) use ($auteur, $dernierSuperAdmin): LigneUtilisateur {
            $superAdmin = $this->estSuperAdmin($user);

            return new LigneUtilisateur(
                $user,
                $superAdmin,
                modifiable: !$superAdmin,
                supprimable: !$superAdmin && $auteur?->getId() !== $user->getId(),
                superAdminRetirable: $superAdmin && !$dernierSuperAdmin,
            );
        }, $users);
    }

    public function estSuperAdmin(?User $user): bool
    {
        return $user?->estSuperAdmin() === true;
    }

    public function compterSuperAdmins(): int
    {
        return (int) $this->users->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.superAdmin = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Le mot de passe d'un super-admin ne se change pas depuis cet écran — le sien se change
     * dans « Mon profil », celui d'un autre ne se prend pas. Pour les autres comptes, c'est
     * le sens même de « gérer les comptes » : sans réinitialisation en libre-service, un mot
     * de passe oublié n'a pas d'autre issue.
     */
    public function peutChangerLeMotDePasseDe(User $cible): bool
    {
        return !$this->estSuperAdmin($cible);
    }
}

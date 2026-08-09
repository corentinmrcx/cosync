<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes administrateurs et règles qui les protègent.
 *
 * Le compte de diagnostic est le super-admin du club : lui seul peut changer les mots de
 * passe, et personne ne peut le supprimer — sans quoi une mauvaise manipulation fermerait
 * définitivement l'accès à l'application.
 */
final class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly BetaModeService $betaModeService,
    ) {}

    public function creer(User $user, string $motDePasse): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $motDePasse));

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

    public function estSuperAdmin(?User $user): bool
    {
        $emailSuperAdmin = $this->betaModeService->getRedirectEmail();

        return $emailSuperAdmin !== '' && $user?->getEmail() === $emailSuperAdmin;
    }

    /** Le super-admin change les mots de passe des autres, jamais le sien par cet écran. */
    public function peutChangerLeMotDePasseDe(User $cible, ?User $auteur): bool
    {
        return $this->estSuperAdmin($auteur) && !$this->estSuperAdmin($cible);
    }
}

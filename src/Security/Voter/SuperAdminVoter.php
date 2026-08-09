<?php declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\UserService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Les écrans de diagnostic (purge, bascule bêta, envoi de mails de test) ne sont ouverts
 * qu'au compte super-admin du club.
 *
 * @extends Voter<string, mixed>
 */
final class SuperAdminVoter extends Voter
{
    public const ACCES_DIAGNOSTIC = 'ACCES_DIAGNOSTIC';

    public function __construct(
        private readonly UserService $userService,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCES_DIAGNOSTIC;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $this->userService->estSuperAdmin($user);
    }
}

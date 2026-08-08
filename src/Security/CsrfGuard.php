<?php declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * Point unique de vérification des jetons CSRF des formulaires.
 *
 * L'échec produit une 403 ; CsrfFailureListener la transforme en retour sur la page
 * précédente lorsque la requête vient d'un navigateur.
 */
final class CsrfGuard
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
    ) {}

    public function valider(string $tokenId, Request $request): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->tokenManager->isTokenValid(new CsrfToken($tokenId, $token))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.', new InvalidCsrfTokenException());
        }
    }
}

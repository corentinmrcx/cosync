<?php declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * Un jeton CSRF invalide vient presque toujours d'une session expirée (onglet resté
 * ouvert), pas d'une attaque : on ramène l'utilisateur sur sa page avec un message
 * plutôt que de lui servir une 403. Sans référent — appel hors navigateur — la 403
 * est rendue telle quelle, sans passer par la redirection vers la page de connexion.
 */
// Priorité supérieure à celle du listener de sécurité, qui transformerait sinon la 403
// en redirection vers la page de connexion.
#[AsEventListener(event: 'kernel.exception', priority: 20)]
final class CsrfFailureListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!$this->estUnEchecCsrf($event->getThrowable())) {
            return;
        }

        $request = $event->getRequest();
        $referent = $request->headers->get('referer');

        if ($referent === null) {
            $event->setResponse(new Response('Jeton CSRF invalide.', Response::HTTP_FORBIDDEN));

            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof Session) {
            $session->getFlashBag()->add('error', 'Session expirée, veuillez réessayer.');
        }

        $event->setResponse(new RedirectResponse($referent));
    }

    private function estUnEchecCsrf(\Throwable $exception): bool
    {
        return $exception instanceof InvalidCsrfTokenException
            || $exception->getPrevious() instanceof InvalidCsrfTokenException;
    }
}

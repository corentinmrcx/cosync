<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
    ) {}

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('security_login');
    }

    #[Route('/login', name: 'security_login')]
    public function login(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
            'last_username' => $this->authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'security_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall Symfony.');
    }
}

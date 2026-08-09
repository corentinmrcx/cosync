<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\ProfileType;
use App\Service\Compte\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/admin/profil', name: 'admin_profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->mettreAJour($user, $form->get('newPassword')->getData());
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('admin_profile');
        }

        return $this->render('admin/profil/index.html.twig', [
            'form' => $form,
        ]);
    }
}

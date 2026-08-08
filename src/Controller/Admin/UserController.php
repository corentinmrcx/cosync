<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\CsrfGuard;
use App\Service\BetaModeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config/utilisateurs', name: 'admin_users_')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly CsrfGuard $csrf,
        private readonly BetaModeService $betaModeService,
    ) {}

    #[Route('', name: 'list')]
    public function list(): Response
    {
        return $this->render('admin/config/utilisateurs/list.html.twig', [
            'users' => $this->userRepo->findBy([], ['email' => 'ASC']),
            'diagEmail' => $this->betaModeService->getRedirectEmail(),
            'isDiag' => $this->isDiagUser(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true, 'can_change_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->hasher->hashPassword($user, $form->get('plainPassword')->getData()));

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('Utilisateur "%s" créé.', $user->getEmail()));

            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/config/utilisateurs/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvel utilisateur',
            'user' => null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        User $user,
        Request $request,
    ): Response {
        $canChangePassword = $this->canChangePasswordFor($user);

        $form = $this->createForm(UserType::class, $user, [
            'is_new' => false,
            'can_change_password' => $canChangePassword,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($canChangePassword && $form->has('plainPassword')) {
                $plain = $form->get('plainPassword')->getData();
                if ($plain !== null && $plain !== '') {
                    $user->setPassword($this->hasher->hashPassword($user, $plain));
                }
            }

            $this->em->flush();

            $this->addFlash('success', sprintf('Utilisateur "%s" mis à jour.', $user->getEmail()));

            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/config/utilisateurs/form.html.twig', [
            'form' => $form,
            'title' => sprintf('Modifier "%s"', $user->getEmail()),
            'user' => $user,
            'canChangePassword' => $canChangePassword,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $this->csrf->valider('delete_user_' . $user->getId(), $request);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('admin_users_list');
        }

        $diagEmail = $this->betaModeService->getRedirectEmail();
        if ($diagEmail !== '' && $user->getEmail() === $diagEmail) {
            $this->addFlash('error', 'Le compte super-admin ne peut pas être supprimé.');

            return $this->redirectToRoute('admin_users_list');
        }

        $email = $user->getEmail();
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', sprintf('Utilisateur "%s" supprimé.', $email));

        return $this->redirectToRoute('admin_users_list');
    }

    private function isDiagUser(): bool
    {
        $diagEmail = $this->betaModeService->getRedirectEmail();
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        return $diagEmail !== '' && $currentUser->getEmail() === $diagEmail;
    }

    private function canChangePasswordFor(User $target): bool
    {
        if (!$this->isDiagUser()) {
            return false;
        }

        $diagEmail = $this->betaModeService->getRedirectEmail();

        return $target->getEmail() !== $diagEmail;
    }
}

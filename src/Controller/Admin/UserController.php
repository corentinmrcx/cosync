<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config/utilisateurs', name: 'admin_users_')]
class UserController extends AbstractController
{
    #[Route('', name: 'list')]
    public function list(UserRepository $userRepo): Response
    {
        return $this->render('admin/config/utilisateurs/list.html.twig', [
            'users' => $userRepo->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setRoles($form->get('isAdmin')->getData() ? ['ROLE_ADMIN'] : []);

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', sprintf('Utilisateur "%s" créé.', $user->getEmail()));
            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/config/utilisateurs/form.html.twig', [
            'form'  => $form,
            'title' => 'Nouvel utilisateur',
            'user'  => null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        User $user,
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(UserType::class, $user, [
            'is_new'   => false,
            'is_admin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('plainPassword')->getData();
            if ($plain !== null && $plain !== '') {
                $user->setPassword($hasher->hashPassword($user, $plain));
            }
            $user->setRoles($form->get('isAdmin')->getData() ? ['ROLE_ADMIN'] : []);

            $em->flush();

            $this->addFlash('success', sprintf('Utilisateur "%s" mis à jour.', $user->getEmail()));
            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/config/utilisateurs/form.html.twig', [
            'form'  => $form,
            'title' => sprintf('Modifier "%s"', $user->getEmail()),
            'user'  => $user,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('admin_users_list');
        }

        $email = $user->getEmail();
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', sprintf('Utilisateur "%s" supprimé.', $email));
        return $this->redirectToRoute('admin_users_list');
    }
}

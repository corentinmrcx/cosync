<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\ProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/profil', name: 'admin_profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();
            if ($newPassword !== null && $newPassword !== '') {
                $user->setPassword($this->hasher->hashPassword($user, $newPassword));
            }

            $this->em->flush();

            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('admin_profile');
        }

        return $this->render('admin/profil/index.html.twig', [
            'form' => $form,
        ]);
    }
}

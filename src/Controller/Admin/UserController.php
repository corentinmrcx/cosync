<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\CsrfGuard;
use App\Service\Compte\UserService;
use Doctrine\Common\Collections\Collection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/club/utilisateurs', name: 'admin_users_')]
#[IsGranted(Permission::UTILISATEUR_GERER->value)]
class UserController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly UserService $userService,
        private readonly UserRepository $userRepo,
    ) {}

    #[Route('', name: 'list')]
    public function list(#[CurrentUser] ?User $currentUser): Response
    {
        return $this->render('admin/club/utilisateurs/list.html.twig', [
            'lignes' => $this->userService->lignes($this->userRepo->findBy([], ['email' => 'ASC']), $currentUser),
            'isDiag' => $this->userService->estSuperAdmin($currentUser),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true, 'can_change_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->creer(
                $user,
                (string) $form->get('plainPassword')->getData(),
                $this->rolesChoisis($form),
            );
            $this->addFlash('success', sprintf('Utilisateur "%s" créé.', $user->getEmail()));

            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/club/utilisateurs/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvel utilisateur',
            'user' => null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, #[CurrentUser] ?User $currentUser): Response
    {
        $canChangePassword = $this->userService->peutChangerLeMotDePasseDe($user);

        $form = $this->createForm(UserType::class, $user, [
            'is_new' => false,
            'can_change_password' => $canChangePassword,
            'roles_actuels' => $user->getRolesAcces()->toArray(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->remplacerRoles($user, $this->rolesChoisis($form));
            $this->userService->mettreAJour(
                $user,
                $canChangePassword && $form->has('plainPassword') ? $form->get('plainPassword')->getData() : null,
            );
            $this->addFlash('success', sprintf('Utilisateur "%s" mis à jour.', $user->getEmail()));

            return $this->redirectToRoute('admin_users_list');
        }

        return $this->render('admin/club/utilisateurs/form.html.twig', [
            'form' => $form,
            'title' => sprintf('Modifier "%s"', $user->getEmail()),
            'user' => $user,
            'canChangePassword' => $canChangePassword,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request, #[CurrentUser] User $currentUser): Response
    {
        $this->csrf->valider('delete_user_' . $user->getId(), $request);

        $email = $user->getEmail();

        try {
            $this->userService->supprimer($user, $currentUser);
            $this->addFlash('success', sprintf('Utilisateur "%s" supprimé.', $email));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_users_list');
    }

    /**
     * Désigner ou retirer un super-admin.
     *
     * Réservé aux super-admins, et pas seulement à `utilisateur.gerer` : c'est le seul geste
     * qui accorde *tous* les droits d'un coup, y compris ceux qu'on n'a pas soi-même.
     */
    #[Route('/{id}/super-admin', name: 'super_admin', methods: ['POST'])]
    public function superAdmin(User $user, Request $request, #[CurrentUser] ?User $currentUser): Response
    {
        if (!$this->userService->estSuperAdmin($currentUser)) {
            throw $this->createAccessDeniedException('Seul un super-admin peut en désigner un autre.');
        }

        $this->csrf->valider('super_admin_user_' . $user->getId(), $request);

        $valeur = $request->request->getBoolean('valeur');

        try {
            $this->userService->definirSuperAdmin($user, $valeur);
            $this->addFlash('success', $valeur
                ? sprintf('"%s" est désormais super-admin.', $user->getEmail())
                : sprintf('"%s" n\'est plus super-admin.', $user->getEmail()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_users_list');
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<RoleAcces>
     */
    private function rolesChoisis(FormInterface $form): array
    {
        $choisis = $form->get('rolesAcces')->getData();

        if ($choisis instanceof Collection) {
            $choisis = $choisis->toArray();
        }

        return array_values(is_array($choisis) ? $choisis : []);
    }
}

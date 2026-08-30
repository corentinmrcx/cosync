<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RoleAcces;
use App\Enum\DomainePermission;
use App\Enum\Permission;
use App\Security\CsrfGuard;
use App\Service\Compte\RoleAccesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les rôles d'accès : ce que le club compose lui-même.
 *
 * Le catalogue des permissions, lui, est du code ({@see Permission}) — l'écran ne fait que
 * cocher dedans. Pas de formulaire Symfony : la liste des cases est dérivée du catalogue à
 * chaque rendu, et un `FormType` n'aurait fait que redécrire l'enum une seconde fois.
 */
#[Route('/admin/club/roles', name: 'admin_roles_')]
#[IsGranted(Permission::UTILISATEUR_GERER->value)]
class RoleAccesController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly RoleAccesService $service,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/club/roles/index.html.twig', [
            'lignes' => $this->service->lignes(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('role_acces', $request);

            try {
                $role = $this->service->creer(
                    (string) $request->request->get('nom'),
                    $this->permissionsCochees($request),
                );
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('admin/club/roles/form.html.twig', $this->donneesFormulaire(
                    null,
                    (string) $request->request->get('nom'),
                    $this->permissionsCochees($request),
                ));
            }

            $this->addFlash('success', sprintf('Rôle « %s » créé.', $role->getNom()));

            return $this->redirectToRoute('admin_roles_index');
        }

        return $this->render('admin/club/roles/form.html.twig', $this->donneesFormulaire(null, '', []));
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(RoleAcces $role, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('role_acces', $request);

            try {
                $this->service->mettreAJour(
                    $role,
                    (string) $request->request->get('nom'),
                    $this->permissionsCochees($request),
                );
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('admin/club/roles/form.html.twig', $this->donneesFormulaire(
                    $role,
                    (string) $request->request->get('nom'),
                    $this->permissionsCochees($request),
                ));
            }

            $this->addFlash('success', sprintf('Rôle « %s » enregistré.', $role->getNom()));

            return $this->redirectToRoute('admin_roles_index');
        }

        return $this->render('admin/club/roles/form.html.twig', $this->donneesFormulaire(
            $role,
            $role->getNom(),
            $role->getPermissions(),
        ));
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(RoleAcces $role, Request $request): Response
    {
        $this->csrf->valider('role_acces_delete_' . $role->getId(), $request);

        $nom = $role->getNom();

        try {
            $this->service->supprimer($role);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_roles_index');
        }

        $this->addFlash('success', sprintf('Rôle « %s » supprimé.', $nom));

        return $this->redirectToRoute('admin_roles_index');
    }

    /**
     * @param list<Permission> $permissions
     *
     * @return array<string, mixed>
     */
    private function donneesFormulaire(?RoleAcces $role, string $nom, array $permissions): array
    {
        return [
            'role' => $role,
            'nom' => $nom,
            'cochees' => array_map(static fn (Permission $p): string => $p->value, $permissions),
            'domaines' => DomainePermission::cases(),
            'implications' => $this->implications(),
        ];
    }

    /**
     * La carte « cette permission en accorde d'autres », telle que l'écran doit la refléter.
     *
     * Elle est envoyée au navigateur pour cocher et verrouiller les lectures au clic. Le
     * serveur la rejoue de son côté ({@see \App\Service\Compte\PermissionCollector::completer()}) :
     * un onglet resté ouvert pendant un déploiement enverrait sinon une sélection incohérente.
     *
     * @return array<string, list<string>>
     */
    private function implications(): array
    {
        $carte = [];

        foreach (Permission::cases() as $permission) {
            $impliquees = array_map(static fn (Permission $p): string => $p->value, $permission->implique());

            if ($impliquees !== []) {
                $carte[$permission->value] = $impliquees;
            }
        }

        return $carte;
    }

    /** @return list<Permission> */
    private function permissionsCochees(Request $request): array
    {
        /** @var list<string> $valeurs */
        $valeurs = $request->request->all('permissions');

        return Permission::depuisValeurs($valeurs);
    }
}

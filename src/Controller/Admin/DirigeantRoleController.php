<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DirigeantRole;
use App\Repository\DirigeantRoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/dirigeant-roles', name: 'admin_dirigeant_roles_')]
class DirigeantRoleController extends AbstractController
{
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        DirigeantRoleRepository $roleRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $body = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('dirigeant_role_create', $body['_token'] ?? '')) {
            return $this->json(['error' => 'Token CSRF invalide.'], 403);
        }

        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            return $this->json(['error' => 'Le rôle ne peut pas être vide.'], 422);
        }

        $label = mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');

        $existing = $roleRepo->findByLabel($label);
        if ($existing !== null) {
            return $this->json(['id' => $existing->getId(), 'label' => $existing->getLabel()]);
        }

        $role = new DirigeantRole();
        $role->setLabel($label);
        $em->persist($role);
        $em->flush();

        return $this->json(['id' => $role->getId(), 'label' => $role->getLabel()], 201);
    }
}

<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Attribute\AccesLibre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Réglages de l'association, valables pour toutes les saisons. */
#[Route('/admin/club', name: 'admin_club_index')]
#[AccesLibre('Point de navigation : chaque carte du hub porte sa propre permission.')]
class ClubController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/club/index.html.twig');
    }
}

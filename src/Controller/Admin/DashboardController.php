<?php declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Racine de l'administration : deux portes, les saisons et le club. La saison de travail est
 * rappelée par un raccourci, rendu depuis le global Twig navbar_current_season.
 */
#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }
}

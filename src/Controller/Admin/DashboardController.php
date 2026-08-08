<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'hasSeason' => $this->seasonContext->getCurrentSeason() !== null,
        ]);
    }
}

<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\SeasonRepository;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config', name: 'admin_config_')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(SeasonRepository $seasonRepo, SeasonContext $seasonContext): Response
    {
        return $this->render('admin/config/index.html.twig', [
            'currentSeason' => $seasonContext->getCurrentSeason(),
            'seasons'       => $seasonRepo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }
}

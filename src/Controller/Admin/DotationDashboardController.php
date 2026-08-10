<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Service\Stock\AchatService;
use App\Service\Stock\CommandeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée des dotations de la saison : ce que le club doit remettre à ses licenciés,
 * et ce qu'il reste à acheter pour y arriver. Le stock physique, lui, se gère au niveau club.
 */
#[Route('/admin/dotations', name: 'admin_dotations_')]
class DotationDashboardController extends AbstractController
{
    public function __construct(
        private readonly AchatService $achatService,
        private readonly CommandeService $commandeService,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        return $this->render('admin/dotations/dashboard.html.twig', [
            'season' => $season,
            'aCommanderCount' => $this->achatService->compterACommander($season),
            'commandesEnAttente' => $this->commandeService->compterEnAttente($season),
        ]);
    }
}

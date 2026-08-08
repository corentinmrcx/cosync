<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CleMouvementRepository;
use App\Service\ClubHouse\CleRegistreService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/club-house', name: 'admin_clubhouse_')]
class ClubHouseController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        SeasonContext $seasonContext,
        CleRegistreService $registre,
        CleMouvementRepository $mouvementRepo,
    ): Response {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/clubhouse/index.html.twig', [
            'season' => $season,
            'stats' => $registre->getStats($season),
            'recents' => $mouvementRepo->findRecentBySeason($season, 5),
        ]);
    }
}

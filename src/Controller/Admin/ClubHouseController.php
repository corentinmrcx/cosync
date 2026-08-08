<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Repository\CleMouvementRepository;
use App\Service\ClubHouse\CleRegistreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/club-house', name: 'admin_clubhouse_')]
class ClubHouseController extends AbstractController
{
    public function __construct(
        private readonly CleRegistreService $registre,
        private readonly CleMouvementRepository $mouvementRepo,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentSeason] Season $season,
    ): Response {
        return $this->render('admin/clubhouse/index.html.twig', [
            'season' => $season,
            'stats' => $this->registre->getStats($season),
            'recents' => $this->mouvementRepo->findRecentBySeason($season, 5),
        ]);
    }
}

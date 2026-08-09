<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Service\Effectif\EffectifPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/effectif', name: 'admin_effectif_')]
class EffectifController extends AbstractController
{
    public function __construct(
        private readonly EffectifPresenter $presenter,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentSeason] Season $season,
    ): Response {
        return $this->render('admin/effectif/index.html.twig', [
            'season' => $season,
            'data' => $this->presenter->getTableauDeBord($season),
        ]);
    }
}

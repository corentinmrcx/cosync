<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Enum\Permission;
use App\Service\Effectif\EffectifPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/effectif', name: 'admin_effectif_')]
#[IsGranted(Permission::EFFECTIF_LIRE->value)]
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

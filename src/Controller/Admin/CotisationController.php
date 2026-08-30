<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Enum\Permission;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\TeamService;
use App\Service\Saison\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tous les montants dus de la saison au même endroit : la cotisation par défaut et,
 * pour chaque équipe, le montant qui la remplace éventuellement.
 */
#[Route('/admin/saison/cotisations', name: 'admin_cotisations_')]
#[IsGranted(Permission::SAISON_LIRE->value)]
class CotisationController extends AbstractController
{
    public function __construct(
        private readonly SeasonService $seasonService,
        private readonly TeamService $teamService,
        private readonly TeamRepository $teamRepo,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentSeason] Season $season,
    ): Response {
        return $this->render('admin/saison/cotisations.html.twig', [
            'season' => $season,
            'teams' => $this->teamRepo->findBySeason($season),
        ]);
    }

    #[Route('/defaut', name: 'defaut', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function defaut(
        #[CurrentSeason] Season $season,
        Request $request,
    ): Response {
        $this->csrf->valider('cotisation_defaut', $request);

        $montant = (int) $request->request->get('cotisation_defaut');
        if ($montant < 0) {
            $this->addFlash('error', 'Une cotisation ne peut pas être négative.');

            return $this->redirectToRoute('admin_cotisations_index');
        }

        $this->seasonService->definirCotisationDefaut($season, $montant);
        $this->addFlash('success', 'Cotisation par défaut mise à jour.');

        return $this->redirectToRoute('admin_cotisations_index');
    }

    /**
     * Les équipes se règlent d'un bloc : on ajuste plusieurs montants, on enregistre une fois.
     */
    #[Route('/equipes', name: 'equipes', methods: ['POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function equipes(
        #[CurrentSeason] Season $season,
        Request $request,
    ): Response {
        $this->csrf->valider('cotisations_equipes', $request);

        try {
            $this->teamService->definirCotisations($season, $request->request->all('cotisations'));
            $this->addFlash('success', 'Cotisations par équipe mises à jour.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cotisations_index');
    }
}

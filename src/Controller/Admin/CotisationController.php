<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\Referentiel\TeamService;
use App\Service\Saison\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tous les montants dus de la saison au même endroit : la cotisation par défaut et,
 * pour chaque équipe, le montant qui la remplace éventuellement.
 */
#[Route('/admin/saison/cotisations', name: 'admin_cotisations_')]
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

    #[Route('/equipes/{id}', name: 'equipe', methods: ['POST'])]
    public function equipe(Team $team, Request $request): Response
    {
        $this->csrf->valider('cotisation_team_' . $team->getId(), $request);

        $saisie = trim((string) $request->request->get('cotisation'));

        try {
            $this->teamService->definirCotisation($team, $saisie === '' ? null : (int) $saisie);
            $this->addFlash('success', sprintf('Cotisation de "%s" mise à jour.', $team->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cotisations_index');
    }
}

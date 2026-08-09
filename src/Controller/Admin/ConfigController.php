<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\TeamEditData;
use App\DTO\TeamSetupData;
use App\Entity\Team;
use App\Form\SeasonType;
use App\Form\TeamSetupType;
use App\Repository\CategoryRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use App\Service\TeamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config', name: 'admin_config_')]
class ConfigController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly SeasonService $seasonService,
        private readonly TeamService $teamService,
        private readonly TeamRepository $teamRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();

        // Seul écran atteignable sans saison : c'est ici qu'on en crée une.
        if ($season === null) {
            return $this->render('admin/config/index.html.twig', [
                'form' => null,
                'season' => null,
                'teams' => [],
                'categories' => [],
                'newTeamForm' => null,
            ]);
        }

        $form = $this->createForm(SeasonType::class, $season, [
            'start_year' => $this->seasonService->anneeDeDebut($season),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->seasonService->renommerParAnnee($season, (int) $form->get('startYear')->getData());
            $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));

            return $this->redirectToRoute('admin_config_index');
        }

        return $this->render('admin/config/index.html.twig', [
            'form' => $form,
            'season' => $season,
            'teams' => $this->teamRepo->findBySeason($season),
            'newTeamForm' => $this->createForm(TeamSetupType::class, new TeamSetupData(), [
                'action' => $this->generateUrl('admin_config_teams_new'),
            ]),
            'categories' => $this->categoryRepo->findBy([], ['minYear' => 'ASC']),
        ]);
    }

    #[Route('/equipes/nouveau', name: 'teams_new', methods: ['POST'])]
    public function teamNew(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_config_index');
        }

        $data = new TeamSetupData();
        $form = $this->createForm(TeamSetupType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Le nom de l\'équipe est obligatoire.');

            return $this->redirectToRoute('admin_config_index');
        }

        try {
            $team = $this->teamService->creer($data, $season);
            $this->addFlash('success', sprintf('Équipe "%s" créée.', $team->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/modifier', name: 'teams_edit', methods: ['POST'])]
    public function teamEdit(Team $team, Request $request): Response
    {
        $this->csrf->valider('edit_team_' . $team->getId(), $request);

        try {
            $this->teamService->mettreAJour($team, TeamEditData::fromRequest($request));
            $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $team->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/supprimer', name: 'teams_delete', methods: ['POST'])]
    public function teamDelete(Team $team, Request $request): Response
    {
        $this->csrf->valider('delete_team_' . $team->getId(), $request);

        $name = $team->getName();
        $this->teamService->supprimer($team);
        $this->addFlash('success', sprintf('Équipe "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_config_index');
    }
}

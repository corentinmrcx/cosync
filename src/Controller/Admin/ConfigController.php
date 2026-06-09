<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Team;
use App\Form\SeasonType;
use App\Form\TeamType;
use App\Repository\CategoryRepository;
use App\Repository\TeamRepository;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config', name: 'admin_config_')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, SeasonContext $seasonContext, SeasonService $seasonService, TeamRepository $teamRepo, CategoryRepository $categoryRepo): Response
    {
        $season = $seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->render('admin/config/index.html.twig', [
                'form'        => null,
                'season'      => null,
                'teams'       => [],
                'categories'  => [],
                'newTeamForm' => null,
            ]);
        }

        $costs = $season->getBaseCosts();
        [$startYear, $endYear] = $this->parseSeasonYears($season->getLabel());

        $form = $this->createForm(SeasonType::class, $season, [
            'start_year'   => $startYear,
            'end_year'     => $endYear,
            'cout_jeunes'  => $costs['jeunes'] ?? 85,
            'cout_seniors' => $costs['seniors'] ?? 120,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $endYear   = (int) $form->get('endYear')->getData();
            $season->setLabel($startYear . '-' . $endYear);
            $season->setBaseCosts([
                'jeunes'  => $form->get('coutJeunes')->getData(),
                'seniors' => $form->get('coutSeniors')->getData(),
            ]);

            $seasonService->update($season);

            $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));
            return $this->redirectToRoute('admin_config_index');
        }

        $newTeam     = new Team();
        $newTeamForm = $this->createForm(TeamType::class, $newTeam, [
            'action' => $this->generateUrl('admin_config_teams_new'),
        ]);

        return $this->render('admin/config/index.html.twig', [
            'form'        => $form,
            'season'      => $season,
            'teams'       => $teamRepo->findBySeason($season),
            'newTeamForm' => $newTeamForm,
            'categories'  => $categoryRepo->findBy([], ['code' => 'ASC']),
        ]);
    }

    #[Route('/equipes/nouveau', name: 'teams_new', methods: ['POST'])]
    public function teamNew(Request $request, SeasonContext $seasonContext, EntityManagerInterface $em): Response
    {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_config_index');
        }

        $team = new Team();
        $team->setSeason($season);
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($team);
            $em->flush();
            $this->addFlash('success', sprintf('Équipe "%s" créée.', $team->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/modifier', name: 'teams_edit', methods: ['POST'])]
    public function teamEdit(Team $team, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $team->getName()));
        } else {
            $this->addFlash('error', 'Données invalides.');
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/supprimer', name: 'teams_delete', methods: ['POST'])]
    public function teamDelete(Team $team, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_team_' . $team->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_config_index');
        }

        $name = $team->getName();
        $em->remove($team);
        $em->flush();
        $this->addFlash('success', sprintf('Équipe "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_config_index');
    }

    /** @return array{int, int} */
    private function parseSeasonYears(string $label): array
    {
        $parts = explode('-', $label);
        $currentYear = (int) date('Y');

        return [
            (int) ($parts[0] ?? $currentYear),
            (int) ($parts[1] ?? $currentYear + 1),
        ];
    }
}

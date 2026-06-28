<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\TeamSetupData;
use App\Entity\Team;
use App\Form\SeasonType;
use App\Form\TeamSetupType;
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
        $startYear = (int) explode('-', $season->getLabel())[0];

        $form = $this->createForm(SeasonType::class, $season, [
            'start_year'   => $startYear,
            'cout_jeunes'  => $costs['jeunes'] ?? 85,
            'cout_seniors' => $costs['seniors'] ?? 120,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $season->setLabel($startYear . '-' . ($startYear + 1));
            $season->setBaseCosts([
                'jeunes'  => $form->get('coutJeunes')->getData(),
                'seniors' => $form->get('coutSeniors')->getData(),
            ]);

            $seasonService->update($season);

            $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));
            return $this->redirectToRoute('admin_config_index');
        }

        $newTeamForm = $this->createForm(TeamSetupType::class, new TeamSetupData(), [
            'action' => $this->generateUrl('admin_config_teams_new'),
        ]);

        return $this->render('admin/config/index.html.twig', [
            'form'        => $form,
            'season'      => $season,
            'teams'       => $teamRepo->findBySeason($season),
            'newTeamForm' => $newTeamForm,
            'categories'  => $categoryRepo->findBy([], ['minYear' => 'ASC']),
        ]);
    }

    #[Route('/equipes/nouveau', name: 'teams_new', methods: ['POST'])]
    public function teamNew(Request $request, SeasonContext $seasonContext, EntityManagerInterface $em): Response
    {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_config_index');
        }

        $data = new TeamSetupData();
        $form = $this->createForm(TeamSetupType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && trim($data->name) !== '') {
            $team = new Team();
            $team->setName(trim($data->name));
            $team->setSeason($season);
            foreach ($data->categories as $category) {
                $team->addCategory($category);
            }
            $em->persist($team);
            $em->flush();
            $this->addFlash('success', sprintf('Équipe "%s" créée.', $team->getName()));
        } else {
            $this->addFlash('error', 'Le nom de l\'équipe est obligatoire.');
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/modifier', name: 'teams_edit', methods: ['POST'])]
    public function teamEdit(Team $team, Request $request, EntityManagerInterface $em, CategoryRepository $categoryRepo): Response
    {
        if (!$this->isCsrfTokenValid('edit_team_' . $team->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_config_index');
        }

        $teamData    = $request->request->all('team');
        $name        = trim($teamData['name'] ?? '');
        $categoryIds = $teamData['categories'] ?? [];

        if ($name === '') {
            $this->addFlash('error', 'Le nom ne peut pas être vide.');
            return $this->redirectToRoute('admin_config_index');
        }

        $team->setName($name);

        $team->getCategories()->clear();
        foreach ($categoryIds as $catId) {
            $cat = $categoryRepo->find((int) $catId);
            if ($cat !== null) {
                $team->addCategory($cat);
            }
        }

        $em->flush();
        $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $team->getName()));

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
}

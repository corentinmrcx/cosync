<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\TeamSetupData;
use App\Entity\Team;
use App\Form\SeasonType;
use App\Form\TeamSetupType;
use App\Repository\CategoryRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
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
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly SeasonService $seasonService,
        private readonly TeamRepository $teamRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly EntityManagerInterface $em,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->render('admin/config/index.html.twig', [
                'form' => null,
                'season' => null,
                'teams' => [],
                'categories' => [],
                'newTeamForm' => null,
            ]);
        }

        $startYear = (int) explode('-', $season->getLabel())[0];

        $form = $this->createForm(SeasonType::class, $season, [
            'start_year' => $startYear,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $season->setLabel($startYear . '-' . ($startYear + 1));

            $this->seasonService->update($season);

            $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));

            return $this->redirectToRoute('admin_config_index');
        }

        $newTeamForm = $this->createForm(TeamSetupType::class, new TeamSetupData(), [
            'action' => $this->generateUrl('admin_config_teams_new'),
        ]);

        return $this->render('admin/config/index.html.twig', [
            'form' => $form,
            'season' => $season,
            'teams' => $this->teamRepo->findBySeason($season),
            'newTeamForm' => $newTeamForm,
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

        if ($form->isSubmitted() && $form->isValid() && trim($data->name) !== '') {
            $team = new Team();
            $team->setName(trim($data->name));
            $team->setSeason($season);
            $team->setCotisation($data->cotisation);
            foreach ($data->categories as $category) {
                $team->addCategory($category);
            }
            $this->em->persist($team);
            $this->em->flush();
            $this->addFlash('success', sprintf('Équipe "%s" créée.', $team->getName()));
        } else {
            $this->addFlash('error', 'Le nom de l\'équipe est obligatoire.');
        }

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/modifier', name: 'teams_edit', methods: ['POST'])]
    public function teamEdit(Team $team, Request $request): Response
    {
        $this->csrf->valider('edit_team_' . $team->getId(), $request);

        $teamData = $request->request->all('team');
        $name = trim($teamData['name'] ?? '');
        $categoryIds = $teamData['categories'] ?? [];
        $cotisation = trim((string) ($teamData['cotisation'] ?? ''));

        if ($name === '') {
            $this->addFlash('error', 'Le nom ne peut pas être vide.');

            return $this->redirectToRoute('admin_config_index');
        }

        $team->setName($name);
        $team->setCotisation($cotisation === '' ? null : (int) $cotisation);

        $team->getCategories()->clear();
        foreach ($categoryIds as $catId) {
            $cat = $this->categoryRepo->find((int) $catId);
            if ($cat !== null) {
                $team->addCategory($cat);
            }
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $team->getName()));

        return $this->redirectToRoute('admin_config_index');
    }

    #[Route('/equipes/{id}/supprimer', name: 'teams_delete', methods: ['POST'])]
    public function teamDelete(Team $team, Request $request): Response
    {
        $this->csrf->valider('delete_team_' . $team->getId(), $request);

        $name = $team->getName();
        $this->em->remove($team);
        $this->em->flush();
        $this->addFlash('success', sprintf('Équipe "%s" supprimée.', $name));

        return $this->redirectToRoute('admin_config_index');
    }
}

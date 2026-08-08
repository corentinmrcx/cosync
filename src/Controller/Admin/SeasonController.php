<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Form\SeasonType;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Repository\SeasonRepository;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config/saisons', name: 'admin_seasons_')]
class SeasonController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        SeasonRepository $seasonRepo,
        LicencieRepository $licencieRepo,
        DirigeantRepository $dirigeantRepo,
        SeasonContext $seasonContext,
    ): Response {
        $seasons = $seasonRepo->findAllOrdered();
        $current = $seasonContext->getCurrentSeason();

        $stats = [];
        foreach ($seasons as $season) {
            $stats[$season->getId()] = [
                'licencies' => $licencieRepo->count(['season' => $season]),
                'dirigeants' => $dirigeantRepo->count(['season' => $season]),
            ];
        }

        return $this->render('admin/seasons/list.html.twig', [
            'seasons' => $seasons,
            'current' => $current,
            'stats' => $stats,
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SeasonService $seasonService): Response
    {
        $season = new Season();
        $form = $this->createForm(SeasonType::class, $season);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $season->setLabel($startYear . '-' . ($startYear + 1));

            try {
                $seasonService->create($season);
                $this->addFlash('success', sprintf('Saison "%s" créée.', $season->getLabel()));

                return $this->redirectToRoute('admin_config_index');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvelle saison',
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(
        Season $season,
        Request $request,
        SeasonService $seasonService,
        SeasonContext $seasonContext,
        LicencieRepository $licencieRepo,
        DirigeantRepository $dirigeantRepo,
    ): Response {
        if (!$this->isCsrfTokenValid('season_delete_' . $season->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_seasons_list');
        }

        $current = $seasonContext->getCurrentSeason();
        if ($current !== null && $current->getId() === $season->getId()) {
            $this->addFlash('error', 'Impossible de supprimer la saison active. Activez d\'abord une autre saison.');

            return $this->redirectToRoute('admin_seasons_list');
        }

        $licencieCount = $licencieRepo->count(['season' => $season]);
        $dirigeantCount = $dirigeantRepo->count(['season' => $season]);

        if ($licencieCount > 0 || $dirigeantCount > 0) {
            $this->addFlash('error', sprintf(
                'Impossible de supprimer "%s" : elle contient %d licencié(s) et %d dirigeant(s).',
                $season->getLabel(),
                $licencieCount,
                $dirigeantCount,
            ));

            return $this->redirectToRoute('admin_seasons_list');
        }

        $label = $season->getLabel();
        $seasonService->delete($season);
        $this->addFlash('success', sprintf('Saison "%s" supprimée.', $label));

        return $this->redirectToRoute('admin_seasons_list');
    }

    #[Route('/{id}/switch', name: 'switch', methods: ['POST'])]
    public function switch(Season $season, SeasonContext $seasonContext, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('season_switch_' . $season->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $seasonContext->setCurrentSeason($season);

        $returnTo = $request->request->get('returnTo', '');
        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('admin_dashboard');
    }
}

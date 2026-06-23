<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Form\SeasonType;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config/saisons', name: 'admin_seasons_')]
class SeasonController extends AbstractController
{
    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SeasonService $seasonService): Response
    {
        $season = new Season();
        $form   = $this->createForm(SeasonType::class, $season);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $endYear   = (int) $form->get('endYear')->getData();
            $season->setLabel($startYear . '-' . $endYear);
            $season->setBaseCosts([
                'jeunes'  => $form->get('coutJeunes')->getData(),
                'seniors' => $form->get('coutSeniors')->getData(),
            ]);

            $seasonService->create($season);

            $this->addFlash('success', sprintf('Saison "%s" créée.', $season->getLabel()));
            return $this->redirectToRoute('admin_config_index');
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form'  => $form,
            'title' => 'Nouvelle saison',
        ]);
    }

    #[Route('/{id}/reglement', name: 'reglement', methods: ['GET', 'POST'])]
    public function reglement(Season $season, Request $request, SeasonService $seasonService): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reglement_' . $season->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_seasons_reglement', ['id' => $season->getId()]);
            }

            $seasonService->updateReglement($season, $request->request->get('reglement_text') ?: null);

            $this->addFlash('success', 'Règlement mis à jour.');
            return $this->redirectToRoute('admin_config_index');
        }

        return $this->render('admin/seasons/reglement.html.twig', [
            'season' => $season,
        ]);
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

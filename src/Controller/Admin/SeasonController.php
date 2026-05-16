<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Form\SeasonType;
use App\Repository\SeasonRepository;
use App\Service\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/saisons', name: 'admin_seasons_')]
class SeasonController extends AbstractController
{
    #[Route('', name: 'list')]
    public function list(SeasonRepository $seasonRepo): Response
    {
        return $this->render('admin/seasons/list.html.twig', [
            'seasons' => $seasonRepo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SeasonService $seasonService): Response
    {
        $season = new Season();
        $form   = $this->createForm(SeasonType::class, $season);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $season->setBaseCosts([
                'jeunes'  => $form->get('coutJeunes')->getData(),
                'seniors' => $form->get('coutSeniors')->getData(),
            ]);

            $seasonService->create($season);

            $this->addFlash('success', sprintf('Saison "%s" créée.', $season->getLabel()));
            return $this->redirectToRoute('admin_seasons_list');
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form'  => $form,
            'title' => 'Nouvelle saison',
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Season $season, Request $request, SeasonService $seasonService): Response
    {
        $costs = $season->getBaseCosts();
        $form  = $this->createForm(SeasonType::class, $season, [
            'cout_jeunes'  => $costs['jeunes'] ?? 85,
            'cout_seniors' => $costs['seniors'] ?? 120,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $season->setBaseCosts([
                'jeunes'  => $form->get('coutJeunes')->getData(),
                'seniors' => $form->get('coutSeniors')->getData(),
            ]);

            $seasonService->update($season);

            $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));
            return $this->redirectToRoute('admin_seasons_list');
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form'   => $form,
            'title'  => sprintf('Modifier "%s"', $season->getLabel()),
            'season' => $season,
        ]);
    }

    #[Route('/{id}/activer', name: 'activate', methods: ['POST'])]
    public function activate(Season $season, SeasonService $seasonService): Response
    {
        $seasonService->activate($season);

        $this->addFlash('success', sprintf('Saison "%s" activée.', $season->getLabel()));
        return $this->redirectToRoute('admin_seasons_list');
    }
}

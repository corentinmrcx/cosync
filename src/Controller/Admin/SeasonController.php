<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Form\SeasonType;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Doctrine\ORM\EntityManagerInterface;
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

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Season $season, Request $request, SeasonService $seasonService): Response
    {
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

        return $this->render('admin/seasons/form.html.twig', [
            'form'   => $form,
            'title'  => sprintf('Modifier "%s"', $season->getLabel()),
            'season' => $season,
        ]);
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

    #[Route('/{id}/reglement', name: 'reglement', methods: ['GET', 'POST'])]
    public function reglement(Season $season, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $season->setReglementText($request->request->get('reglement_text') ?: null);
            $em->flush();

            $this->addFlash('success', 'Règlement mis à jour.');
            return $this->redirectToRoute('admin_seasons_reglement', ['id' => $season->getId()]);
        }

        return $this->render('admin/seasons/reglement.html.twig', [
            'season' => $season,
        ]);
    }

    #[Route('/{id}/switch', name: 'switch', methods: ['GET'])]
    public function switch(Season $season, SeasonContext $seasonContext, Request $request): Response
    {
        $seasonContext->setCurrentSeason($season);

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('admin_dashboard'));
    }
}

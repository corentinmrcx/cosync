<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\SeasonType;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/config', name: 'admin_config_')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, SeasonContext $seasonContext, SeasonService $seasonService): Response
    {
        $season = $seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->render('admin/config/index.html.twig', [
                'form'   => null,
                'season' => null,
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

        return $this->render('admin/config/index.html.twig', [
            'form'   => $form,
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
}

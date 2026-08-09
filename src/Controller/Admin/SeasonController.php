<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Form\SeasonType;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Repository\SeasonRepository;
use App\Security\CsrfGuard;
use App\Service\Saison\SeasonContext;
use App\Service\Saison\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/saisons', name: 'admin_seasons_')]
class SeasonController extends AbstractController
{
    public function __construct(
        private readonly SeasonRepository $seasonRepo,
        private readonly LicencieRepository $licencieRepo,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly SeasonContext $seasonContext,
        private readonly SeasonService $seasonService,
        private readonly CsrfGuard $csrf,
    ) {}

    /** Une carte par saison : c'est la porte d'entrée vers le travail dans une saison. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/seasons/index.html.twig', [
            'seasons' => $this->seasonRepo->findAllOrdered(),
            'current' => $this->seasonContext->getCurrentSeason(),
        ]);
    }

    // Renommage et suppression : accessible même quand aucune saison n'existe encore.
    #[Route('/gerer', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $seasons = $this->seasonRepo->findAllOrdered();

        return $this->render('admin/seasons/list.html.twig', [
            'seasons' => $seasons,
            'current' => $this->seasonContext->getCurrentSeason(),
            'stats' => $this->compterParSaison($seasons),
        ]);
    }

    /**
     * Effectifs par saison : ils disent pourquoi une saison ne peut pas être supprimée.
     *
     * @param Season[] $seasons
     *
     * @return array<int, array{licencies: int, dirigeants: int}>
     */
    private function compterParSaison(array $seasons): array
    {
        $stats = [];
        foreach ($seasons as $season) {
            $stats[$season->getId()] = [
                'licencies' => $this->licencieRepo->count(['season' => $season]),
                'dirigeants' => $this->dirigeantRepo->count(['season' => $season]),
            ];
        }

        return $stats;
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $season = new Season();
        $form = $this->createForm(SeasonType::class, $season);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startYear = (int) $form->get('startYear')->getData();
            $season->setLabel($startYear . '-' . ($startYear + 1));

            try {
                $this->seasonService->create($season);
                $this->seasonContext->setCurrentSeason($season);
                $this->addFlash('success', sprintf('Saison "%s" créée. Vous y travaillez désormais.', $season->getLabel()));

                return $this->redirectToRoute('admin_saison_dashboard');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form' => $form,
            'season' => null,
            'title' => 'Nouvelle saison',
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Season $season, Request $request): Response
    {
        $form = $this->createForm(SeasonType::class, $season, [
            'start_year' => $this->seasonService->anneeDeDebut($season),
            'avec_cotisation' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->seasonService->renommerParAnnee($season, (int) $form->get('startYear')->getData());
                $this->addFlash('success', sprintf('Saison "%s" mise à jour.', $season->getLabel()));

                return $this->redirectToRoute('admin_seasons_list');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/seasons/form.html.twig', [
            'form' => $form,
            'season' => $season,
            'title' => sprintf('Saison %s', $season->getLabel()),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(
        Season $season,
        Request $request,
    ): Response {
        $this->csrf->valider('season_delete_' . $season->getId(), $request);

        $current = $this->seasonContext->getCurrentSeason();
        if ($current !== null && $current->getId() === $season->getId()) {
            $this->addFlash('error', 'Impossible de supprimer la saison dans laquelle vous travaillez. Entrez d\'abord dans une autre saison.');

            return $this->redirectToRoute('admin_seasons_list');
        }

        $licencieCount = $this->licencieRepo->count(['season' => $season]);
        $dirigeantCount = $this->dirigeantRepo->count(['season' => $season]);

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
        $this->seasonService->delete($season);
        $this->addFlash('success', sprintf('Saison "%s" supprimée.', $label));

        return $this->redirectToRoute('admin_seasons_list');
    }

    #[Route('/{id}/switch', name: 'switch', methods: ['POST'])]
    public function switch(Season $season, Request $request): Response
    {
        $this->csrf->valider('season_switch_' . $season->getId(), $request);

        $this->seasonContext->setCurrentSeason($season);

        $returnTo = $request->request->get('returnTo', '');
        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
            return $this->redirect($returnTo);
        }

        // Basculer de saison, c'est entrer dedans.
        return $this->redirectToRoute('admin_saison_dashboard');
    }
}

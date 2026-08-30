<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Season;
use App\Enum\Permission;
use App\Form\SeasonType;
use App\Repository\SeasonRepository;
use App\Security\Attribute\AccesLibre;
use App\Security\CsrfGuard;
use App\Service\Saison\SeasonContext;
use App\Service\Saison\SeasonService;
use App\Service\Saison\SeasonSuppressionGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/saisons', name: 'admin_seasons_')]
class SeasonController extends AbstractController
{
    public function __construct(
        private readonly SeasonRepository $seasonRepo,
        private readonly SeasonContext $seasonContext,
        private readonly SeasonService $seasonService,
        private readonly SeasonSuppressionGuard $suppressionGuard,
        private readonly CsrfGuard $csrf,
    ) {}

    /** Une carte par saison : c'est la porte d'entrée vers le travail dans une saison. */
    #[Route('', name: 'index', methods: ['GET'])]
    #[AccesLibre('Sélecteur de saison de travail : navigation, pas configuration.')]
    public function index(): Response
    {
        return $this->render('admin/seasons/index.html.twig', [
            'seasons' => $this->seasonRepo->findAllOrdered(),
            'current' => $this->seasonContext->getCurrentSeason(),
        ]);
    }

    // Renommage et suppression : accessible même quand aucune saison n'existe encore.
    #[Route('/gerer', name: 'list', methods: ['GET'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function list(): Response
    {
        $seasons = $this->seasonRepo->findAllOrdered();

        return $this->render('admin/seasons/list.html.twig', [
            'seasons' => $seasons,
            'current' => $this->seasonContext->getCurrentSeason(),
            'blocages' => $this->blocageParSaison($seasons),
        ]);
    }

    /**
     * Pourquoi chaque saison ne peut pas être supprimée : l'écran affiche la raison à la
     * place du bouton, un bouton absent sans explication n'apprend rien à l'admin.
     *
     * @param Season[] $seasons
     *
     * @return array<int, ?string>
     */
    private function blocageParSaison(array $seasons): array
    {
        $blocages = [];
        foreach ($seasons as $season) {
            $blocages[$season->getId()] = $this->suppressionGuard->raison($season);
        }

        return $blocages;
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
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
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
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
    #[IsGranted(Permission::SAISON_CONFIGURER->value)]
    public function delete(
        Season $season,
        Request $request,
    ): Response {
        $this->csrf->valider('season_delete_' . $season->getId(), $request);

        // Le template masque le bouton, mais c'est ce contrôle-ci qui fait foi.
        $raison = $this->suppressionGuard->raison($season);
        if ($raison !== null) {
            $this->addFlash('error', sprintf('Impossible de supprimer "%s". %s.', $season->getLabel(), $raison));

            return $this->redirectToRoute('admin_seasons_list');
        }

        $label = $season->getLabel();
        $current = $this->seasonContext->getCurrentSeason();

        // Supprimer la saison dans laquelle on travaille est permis : sans cela, une saison
        // créée par erreur devient courante à sa création et n'est plus jamais supprimable.
        // On bascule d'abord, sinon l'admin se retrouverait dans une saison qui n'existe plus.
        $repli = $current !== null && $current->getId() === $season->getId()
            ? $this->suppressionGuard->remplacantePour($season)
            : null;

        if ($repli !== null) {
            $this->seasonContext->setCurrentSeason($repli);
        }

        $this->seasonService->delete($season);

        $this->addFlash('success', $repli !== null
            ? sprintf('Saison "%s" supprimée. Vous travaillez désormais sur "%s".', $label, $repli->getLabel())
            : sprintf('Saison "%s" supprimée.', $label));

        return $this->redirectToRoute('admin_seasons_list');
    }

    #[Route('/{id}/switch', name: 'switch', methods: ['POST'])]
    #[AccesLibre('Changer sa saison de travail est un acte personnel de navigation.')]
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

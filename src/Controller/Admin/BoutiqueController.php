<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\EnvoiGroupeResultat;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Repository\LicencieRepository;
use App\Security\CsrfGuard;
use App\Service\Boutique\BoutiqueAnnonceService;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Saison\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil de la boutique du club : son état d'ouverture, son lien, son annonce.
 *
 * Rien ne part d'ici de soi-même. Le club lance ses licences puis sa boutique quelques
 * jours plus tard : l'ouverture et l'annonce sont deux décisions, prises à la main, dans
 * cet ordre.
 */
#[Route('/admin/boutique', name: 'admin_boutique_')]
class BoutiqueController extends AbstractController
{
    public function __construct(
        private readonly ClubSettingsService $clubSettings,
        private readonly LicencieRepository $licencieRepo,
        private readonly BoutiqueAnnonceService $annonceService,
        private readonly SeasonContext $seasonContext,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Pas de `#[CurrentSeason]` ici : la boutique appartient au club, et cet écran doit
        // rester lisible même avant qu'une saison existe.
        $season = $this->seasonContext->getCurrentSeason();
        $settings = $this->clubSettings->get();

        return $this->render('admin/boutique/index.html.twig', [
            'settings' => $settings,
            // Compté seulement quand la boutique est ouverte : tant qu'elle ne l'est pas,
            // annoncer n'est pas une action possible, et un compteur d'attente mentirait.
            'aAnnoncer' => $settings->aBoutique() && $season !== null
                ? $this->licencieRepo->countBoutiqueAAnnoncer($season)
                : 0,
        ]);
    }

    /**
     * Ouverture et fermeture de la boutique. Le lien peut ainsi se préparer à froid : il
     * n'apparaît sur la page de confirmation et dans les mails qu'une fois la boutique
     * ouverte, et refermer la fait disparaître partout sans effacer le lien.
     */
    #[Route('/ouverture', name: 'ouverture', methods: ['POST'])]
    public function ouverture(Request $request): Response
    {
        $this->csrf->valider('boutique_ouverture', $request);

        $settings = $this->clubSettings->get();
        $ouvrir = $request->request->getBoolean('ouvrir');

        if ($ouvrir && $settings->getBoutiqueUrl() === null) {
            $this->addFlash('error', 'Renseignez d\'abord le lien de la boutique.');

            return $this->redirectToRoute('admin_boutique_lien');
        }

        $settings->setBoutiqueOuverte($ouvrir);
        $this->clubSettings->enregistrer();

        $this->addFlash('success', $ouvrir
            ? 'Boutique ouverte : le lien est affiché aux licenciés qui terminent leur inscription.'
            : 'Boutique fermée : le lien n\'est plus annoncé nulle part.');

        return $this->redirectToRoute('admin_boutique_index');
    }

    /**
     * Annonce groupée de la boutique, une seule fois par licencié.
     *
     * L'annonce ne peut pas s'accrocher à la soumission du formulaire : la boutique ouvre
     * après les licences, et ceux qui se sont inscrits entre-temps ne seraient jamais
     * rattrapés. Elle se décide donc ici, après relecture des destinataires.
     */
    #[Route('/annoncer', name: 'annoncer', methods: ['GET', 'POST'])]
    public function annoncer(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $settings = $this->clubSettings->get();

        if (!$settings->aBoutique()) {
            $this->addFlash('error', 'Ouvrez d\'abord la boutique : sans cela, le mail annoncerait un lien que personne ne peut voir.');

            return $this->redirectToRoute('admin_boutique_index');
        }

        $aAnnoncer = $this->licencieRepo->findBoutiqueAAnnoncer($season);

        if ($request->isMethod('POST')) {
            $this->csrf->valider('annoncer_boutique', $request);

            $resultat = $this->annonceService->envoyerEnMasse(
                $aAnnoncer,
                array_map(strval(...), $request->request->all('licencies')),
            );

            $this->addFlash(
                $resultat->envoyes > 0 ? 'success' : 'info',
                $this->resumeEnvoi($resultat),
            );

            return $this->redirectToRoute('admin_boutique_index');
        }

        return $this->render('admin/boutique/annoncer.html.twig', [
            'aAnnoncer' => $aAnnoncer,
            'uuids' => array_map(static fn (Licencie $l): string => (string) $l->getUuid(), $aAnnoncer),
        ]);
    }

    private function resumeEnvoi(EnvoiGroupeResultat $resultat): string
    {
        $parties = [sprintf('%d annonce%s envoyée%s', $resultat->envoyes, $resultat->envoyes > 1 ? 's' : '', $resultat->envoyes > 1 ? 's' : '')];

        if ($resultat->nonRetenus > 0) {
            $parties[] = sprintf('%d décoché%s', $resultat->nonRetenus, $resultat->nonRetenus > 1 ? 's' : '');
        }
        if ($resultat->echecs > 0) {
            $parties[] = sprintf('%d échec%s d\'envoi', $resultat->echecs, $resultat->echecs > 1 ? 's' : '');
        }

        return implode(', ', $parties) . '.';
    }
}

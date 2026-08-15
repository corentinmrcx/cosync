<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DirigeantData;
use App\DTO\EnvoiGroupeResultat;
use App\DTO\FiltreListe;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Form\DirigeantType;
use App\Repository\DirigeantRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\Cle\CleRegistrePresenter;
use App\Service\Dirigeant\DirigeantDossierCompletion;
use App\Service\Dirigeant\DirigeantFormPrefill;
use App\Service\Dirigeant\DirigeantService;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Licencie\HistoriqueFicheService;
use App\Service\Mail\DirigeantLinkService;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/effectif/dirigeants', name: 'admin_dirigeants_')]
class DirigeantController extends AbstractController
{
    public function __construct(
        private readonly ListFilterMemory $filterMemory,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly TeamRepository $teamRepo,
        private readonly DirigeantService $dirigeantService,
        private readonly StockMovementRepository $stockMovementRepo,
        private readonly CleRegistrePresenter $clePresenter,
        private readonly DirigeantLinkService $linkService,
        private readonly CsrfGuard $csrf,
        private readonly DirigeantFormPrefill $formPrefill,
        private readonly HistoriqueFicheService $historiqueService,
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly DirigeantDossierCompletion $dossierCompletion,
    ) {}

    #[Route('', name: 'list')]
    public function list(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $restored = $this->filterMemory->restoreOrRemember('dirigeants', $request, ['team', 'role', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_dirigeants_list', $restored);
        }

        $search = trim((string) $request->query->get('search', ''));
        $currentTeam = null;
        $currentRole = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $this->teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('role') && $request->query->get('role') !== '') {
            // tryFrom : neutralise silencieusement un ancien id numérique encore mémorisé par ListFilterMemory.
            $currentRole = DirigeantRole::tryFrom((string) $request->query->get('role'));
        }

        $filterGroups = [
            FiltreListe::depuisEntites(
                'team',
                'Équipe',
                'Toutes',
                $this->teamRepo->findBySeason($season),
                static fn (Team $team): int => (int) $team->getId(),
                static fn (Team $team): string => $team->getName(),
                $currentTeam?->getId(),
            ),
            FiltreListe::depuisEnum('role', 'Rôle', 'Tous', DirigeantRole::cases(), $currentRole),
        ];

        return $this->render('admin/dirigeants/list.html.twig', [
            'dirigeants' => $this->dirigeantRepo->findBySeasonWithFilters(
                $season,
                $search ?: null,
                $currentTeam?->getId(),
                $currentRole,
            ),
            'season' => $season,
            'search' => $search,
            'filterGroups' => $filterGroups,
            'activeFilterCount' => FiltreListe::compterActifs($filterGroups),
            'liensEnAttente' => $this->dirigeantRepo->countLienJamaisEnvoye($season),
        ]);
    }

    /**
     * Envoi groupé des liens de formulaire dirigeant.
     *
     * Même règle que pour les licenciés : ni l'import ni la création manuelle n'écrivent
     * d'eux-mêmes. L'envoi est une décision, prise sur cet écran qui montre les
     * destinataires avant tout départ.
     *
     * Déclaré avant la route `/{uuid}` : sans cela, « envoyer-liens » serait lu comme un uuid.
     */
    #[Route('/envoyer-liens', name: 'send_links', methods: ['GET', 'POST'])]
    public function sendLinks(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $enAttente = $this->dirigeantRepo->findLienJamaisEnvoye($season);

        if ($request->isMethod('POST')) {
            $this->csrf->valider('envoyer_liens_dirigeants', $request);

            $resultat = $this->linkService->envoyerEnMasse(
                $enAttente,
                array_map(strval(...), $request->request->all('dirigeants')),
            );

            $this->addFlash(
                $resultat->envoyes > 0 ? 'success' : 'info',
                $this->resumeEnvoi($resultat),
            );

            return $this->redirectToRoute('admin_dirigeants_list');
        }

        $joignables = array_values(array_filter(
            $enAttente,
            static fn (Dirigeant $d): bool => $d->getEmail() !== null,
        ));

        return $this->render('admin/dirigeants/envoyer_liens.html.twig', [
            'enAttente' => $enAttente,
            'sansEmail' => count($enAttente) - count($joignables),
            // Tous cochés d'office : le formulaire dirigeant ne dépend d'aucune donnée
            // encore à saisir côté admin — il n'y a rien qui puisse y sonner faux.
            'joignables' => array_map(
                static fn (Dirigeant $d): string => (string) $d->getUuid(),
                $joignables,
            ),
        ]);
    }

    private function resumeEnvoi(EnvoiGroupeResultat $resultat): string
    {
        $parties = [sprintf('%d lien%s envoyé%s', $resultat->envoyes, $resultat->envoyes > 1 ? 's' : '', $resultat->envoyes > 1 ? 's' : '')];

        if ($resultat->nonRetenus > 0) {
            $parties[] = sprintf('%d décoché%s', $resultat->nonRetenus, $resultat->nonRetenus > 1 ? 's' : '');
        }
        if ($resultat->sansEmail > 0) {
            $parties[] = sprintf('%d sans adresse email', $resultat->sansEmail);
        }
        if ($resultat->echecs > 0) {
            $parties[] = sprintf('%d échec%s d\'envoi', $resultat->echecs, $resultat->echecs > 1 ? 's' : '');
        }

        return implode(', ', $parties) . '.';
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $data = new DirigeantData();
        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season, 'envoi_lien' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $dirigeant = $this->dirigeantService->create($data, $season);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('admin/dirigeants/form.html.twig', [
                    'form' => $form,
                    'dirigeant' => null,
                    'roleOptions' => DirigeantRole::options(),
                    'licenciesSizes' => $this->formPrefill->parUuid($season),
                ]);
            }

            // La licence administrative prime sur la case : cochée puis rendue administrative,
            // elle produirait un « échec d'envoi » trompeur là où rien ne devait partir.
            if ($form->get('sendLink')->getData() === true
                && $dirigeant->getEmail() !== null
                && !$dirigeant->isLicenceAdministrative()) {
                try {
                    $this->linkService->send($dirigeant);
                    $this->addFlash('success', $dirigeant->getNomPrenom() . ' ajouté(e) comme dirigeant. Lien envoyé par email.');
                } catch (\Throwable) {
                    $this->addFlash('warning', $dirigeant->getNomPrenom() . ' ajouté(e), mais l\'envoi du mail a échoué. Vérifiez la configuration SMTP.');
                }
            } else {
                $this->addFlash('success', $dirigeant->getNomPrenom() . ' ajouté(e) comme dirigeant.');
            }

            return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form' => $form,
            'dirigeant' => null,
            'roleOptions' => DirigeantRole::options(),
            'licenciesSizes' => $this->formPrefill->parUuid($season),
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
    ): Response {
        $signatures = $this->documentResolver->signaturesParDocumentPourDirigeant($dirigeant);

        return $this->render('admin/dirigeants/show.html.twig', [
            'dirigeant' => $dirigeant,
            'dotations' => $this->stockMovementRepo->findDotationsByDirigeant($dirigeant),
            'history' => $this->historiqueService->pourDirigeant($dirigeant),
            // Détention et engagement de la saison en une lecture : la fiche affiche
            // ce que le registre des clés sait de cette personne, ou rien.
            'cleRow' => $this->clePresenter->pourDirigeant($dirigeant),
            // Documents attendus et leur signature éventuelle : la checklist n'est plus
            // une liste figée, elle suit ce que la saison demande à ce dirigeant.
            'documents' => $this->documentResolver->attendusPourDirigeant($dirigeant),
            'signatures' => $signatures,
            'dossierComplet' => $this->dossierCompletion->isComplete($dirigeant),
        ]);
    }

    #[Route('/{uuid}/envoyer-lien', name: 'send_link', methods: ['POST'])]
    public function sendLink(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
    ): Response {
        $this->csrf->valider('dirigeant_send_link_' . $dirigeant->getUuid(), $request);

        try {
            $this->linkService->send($dirigeant);
            $this->addFlash('success', 'Lien envoyé à ' . $dirigeant->getEmail() . '.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
    ): Response {
        // Saison du dirigeant, pas celle de l'admin : une fiche s'ouvre par UUID sans
        // passer par la liste filtrée, et proposer les équipes ou les licenciés d'une
        // autre saison le rattacherait au mauvais exercice.
        $season = $dirigeant->getSeason();

        $data = new DirigeantData();
        $data->nom = $dirigeant->getNom();
        $data->prenom = $dirigeant->getPrenom();
        $data->email = $dirigeant->getEmail();
        $data->telephone = $dirigeant->getTelephone();
        $data->dateNaissance = $dirigeant->getDateNaissance();
        $data->role = $dirigeant->getRole();
        $data->tailleHaut = $dirigeant->getTailleHaut();
        $data->tailleBas = $dirigeant->getTailleBas();
        $data->pointure = $dirigeant->getPointure();
        $data->team = $dirigeant->getTeam();
        $data->numLicence = $dirigeant->getNumLicence();
        $data->licencie = $dirigeant->getLicencie();
        $data->licenceAdministrative = $dirigeant->isLicenceAdministrative();

        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->dirigeantService->edit($dirigeant, $data);
                $this->addFlash('success', 'Dossier de ' . $dirigeant->getNomPrenom() . ' mis à jour.');

                return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form' => $form,
            'dirigeant' => $dirigeant,
            'roleOptions' => DirigeantRole::options(),
            'licenciesSizes' => $this->formPrefill->parUuid($season),
        ]);
    }
}

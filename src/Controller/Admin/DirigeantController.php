<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DirigeantData;
use App\DTO\Effectif\ResultatSuppression;
use App\DTO\EnvoiGroupeResultat;
use App\DTO\FiltreListe;
use App\DTO\RelanceResultat;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\ChampContact;
use App\Enum\DirigeantRole;
use App\Enum\DirigeantStatut;
use App\Form\DirigeantType;
use App\Repository\DirigeantRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Security\Voter\SuperAdminVoter;
use App\Service\Cle\CleRegistrePresenter;
use App\Service\Dirigeant\DirigeantDossierCompletion;
use App\Service\Dirigeant\DirigeantFormPrefill;
use App\Service\Dirigeant\DirigeantService;
use App\Service\Dirigeant\DirigeantStatutResolver;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Document\SignatureRelanceService;
use App\Service\Effectif\SuppressionFicheService;
use App\Service\Licencie\HistoriqueFicheService;
use App\Service\Mail\DernierContactResolver;
use App\Service\Mail\DirigeantLinkService;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly SignatureRelanceService $relanceService,
        private readonly CsrfGuard $csrf,
        private readonly DirigeantFormPrefill $formPrefill,
        private readonly HistoriqueFicheService $historiqueService,
        private readonly DernierContactResolver $dernierContact,
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly DirigeantDossierCompletion $dossierCompletion,
        private readonly SuppressionFicheService $suppressionService,
        private readonly DirigeantStatutResolver $statutResolver,
    ) {}

    #[Route('', name: 'list')]
    public function list(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        // Le mode édition n'est pas un filtre : jamais mémorisé, seulement reporté sur la
        // redirection de restauration. Cf. la même règle côté joueurs.
        $edition = $request->query->getBoolean('edition') && $this->isGranted(SuperAdminVoter::ACCES_DIAGNOSTIC);

        $restored = $this->filterMemory->restoreOrRemember('dirigeants', $request, ['team', 'role', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_dirigeants_list', $edition ? $restored + ['edition' => 1] : $restored);
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

        $dirigeants = $this->dirigeantRepo->findBySeasonWithFilters(
            $season,
            $search ?: null,
            $currentTeam?->getId(),
            $currentRole,
        );

        return $this->render('admin/dirigeants/list.html.twig', [
            'dirigeants' => $dirigeants,
            // Calculés en lot : ligne par ligne, la complétude coûterait deux requêtes
            // par dirigeant.
            'statuts' => $this->statutResolver->pourLot($season, $dirigeants),
            'season' => $season,
            'search' => $search,
            'filterGroups' => $filterGroups,
            'activeFilterCount' => FiltreListe::compterActifs($filterGroups),
            'liensEnAttente' => $this->dirigeantRepo->countLienJamaisEnvoye($season),
            // Signalé au même endroit que les liens jamais envoyés : c'est la même
            // question — qui reste-t-il à relancer avant que la saison démarre ?
            'signaturesEnAttente' => count($this->relanceService->dirigeants($season)),
            // Ne s'adresse à personne : c'est le club qui a une démarche à faire dans FootClubs.
            'aValiderFff' => count($this->dirigeantsAValiderFff($season)),
            'edition' => $edition,
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

    /**
     * Mode édition : mêmes règles et même écran de confirmation que côté joueurs
     * (cf. `SuppressionFicheService`). Déclaré avant `/{uuid}`.
     */
    /**
     * Relance groupée des signatures manquantes. Déclaré avant la route `/{uuid}`, et
     * jumeau exact de l'écran des joueurs : c'est le même geste, seul le mail diffère.
     */
    #[Route('/demander-signatures', name: 'request_signatures', methods: ['GET', 'POST'])]
    public function requestSignatures(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('demander_signatures_dirigeants', $request);

            $resultat = $this->relanceService->relancerDirigeants(
                $season,
                array_map(strval(...), $request->request->all('personnes')),
            );

            $this->addFlash(
                $resultat->envoyes > 0 ? 'success' : 'info',
                $this->resumeRelance($resultat),
            );

            return $this->redirectToRoute('admin_dirigeants_list');
        }

        $lignes = $this->relanceService->dirigeants($season);

        return $this->render('admin/effectif/demander_signatures.html.twig', [
            'lignes' => $lignes,
            'joignables' => $this->relanceService->uuidsJoignables($lignes),
            'population' => 'dirigeant',
            'tokenId' => 'demander_signatures_dirigeants',
            'actionUrl' => $this->generateUrl('admin_dirigeants_request_signatures'),
            'retourUrl' => $this->generateUrl('admin_dirigeants_list'),
            'intro' => 'Un dirigeant dont le dossier était déjà complet n\'a plus de lien valide : ajouter un document ne le prévient pas. Chaque personne cochée reçoit son lien de formulaire, rouvert pour 30 jours — elle ne refera que ce qui manque.',
        ]);
    }

    /** Le compte rendu nomme ce qui reste à faire à la main : sans email, aucun lien ne part. */
    private function resumeRelance(RelanceResultat $resultat): string
    {
        $resume = sprintf('%d lien%s envoyé%s', $resultat->envoyes, $resultat->envoyes > 1 ? 's' : '', $resultat->envoyes > 1 ? 's' : '');

        if ($resultat->sansEmail > 0) {
            $resume .= sprintf(', %d dirigeant%s sans adresse email à prévenir autrement', $resultat->sansEmail, $resultat->sansEmail > 1 ? 's' : '');
        }

        return $resume . '.';
    }

    /**
     * Validation groupée dans FootClubs. Déclaré avant la route `/{uuid}` : sans cela,
     * « valider-footclubs » serait lu comme un uuid. Frère de l'écran joueurs.
     */
    #[Route('/valider-footclubs', name: 'validate_fff_bulk', methods: ['GET', 'POST'])]
    public function validateFffBulk(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $eligibles = $this->dirigeantsAValiderFff($season);

        if ($request->isMethod('POST')) {
            $this->csrf->valider('valider_footclubs_dirigeants', $request);

            $valides = $this->dirigeantService->validerSurFootclubsEnMasse(
                $eligibles,
                array_map(strval(...), $request->request->all('personnes')),
            );

            $this->addFlash(
                $valides > 0 ? 'success' : 'info',
                sprintf('%d licence%s validée%s.', $valides, $valides > 1 ? 's' : '', $valides > 1 ? 's' : ''),
            );

            return $this->redirectToRoute('admin_dirigeants_list');
        }

        return $this->render('admin/effectif/valider_footclubs.html.twig', [
            'personnes' => array_map(
                static fn (Dirigeant $d): array => [
                    'uuid' => (string) $d->getUuid(),
                    'nom' => $d->getNomPrenom(),
                    'detail' => $d->getRole()->label(),
                ],
                $eligibles,
            ),
            'population' => 'dirigeant',
            'tokenId' => 'valider_footclubs_dirigeants',
            'actionUrl' => $this->generateUrl('admin_dirigeants_validate_fff_bulk'),
            'retourUrl' => $this->generateUrl('admin_dirigeants_list'),
        ]);
    }

    /**
     * Dirigeants dont il ne reste que la signature dans FootClubs.
     *
     * Le statut d'un dirigeant est calculé, jamais stocké : impossible de le filtrer en SQL
     * comme celui d'un joueur. On passe donc par le résolveur, qui lit la saison en un nombre
     * de requêtes fixe.
     *
     * @return Dirigeant[]
     */
    private function dirigeantsAValiderFff(Season $season): array
    {
        $dirigeants = $this->dirigeantRepo->findBySeason($season);
        $statuts = $this->statutResolver->pourLot($season, $dirigeants);

        return array_values(array_filter(
            $dirigeants,
            static fn (Dirigeant $d): bool => $statuts[(string) $d->getUuid()] === DirigeantStatut::A_VALIDER_FFF,
        ));
    }

    #[Route('/supprimer', name: 'delete_confirm', methods: ['POST'])]
    #[IsGranted(SuperAdminVoter::ACCES_DIAGNOSTIC)]
    public function deleteConfirm(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $this->csrf->valider('supprimer_dirigeants', $request);

        $fiches = $this->dirigeantRepo->findByUuidsInSeason(
            array_map(strval(...), $request->request->all('dirigeants')),
            $season,
        );

        if ($fiches === []) {
            $this->addFlash('info', 'Aucune fiche sélectionnée.');

            return $this->redirectToRoute('admin_dirigeants_list', ['edition' => 1]);
        }

        return $this->render('admin/dirigeants/supprimer.html.twig', [
            'analyses' => $this->suppressionService->analyserLot($fiches),
        ]);
    }

    #[Route('/supprimer/confirmer', name: 'delete', methods: ['POST'])]
    #[IsGranted(SuperAdminVoter::ACCES_DIAGNOSTIC)]
    public function delete(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $this->csrf->valider('supprimer_dirigeants_confirmer', $request);

        $fiches = $this->dirigeantRepo->findByUuidsInSeason(
            array_map(strval(...), $request->request->all('dirigeants')),
            $season,
        );

        try {
            $resultat = $this->suppressionService->supprimerLot($fiches);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_dirigeants_list', ['edition' => 1]);
        }

        $this->addFlash(
            $resultat->supprimees > 0 ? 'success' : 'info',
            $this->resumeSuppression($resultat),
        );

        return $this->redirectToRoute('admin_dirigeants_list');
    }

    private function resumeSuppression(ResultatSuppression $resultat): string
    {
        $resume = sprintf(
            '%d fiche%s supprimée%s.',
            $resultat->supprimees,
            $resultat->supprimees > 1 ? 's' : '',
            $resultat->supprimees > 1 ? 's' : '',
        );

        if ($resultat->refusees !== []) {
            $resume .= ' Épargnée(s) : ' . implode(' ; ', $resultat->refusees) . '.';
        }

        return $resume;
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
            // Cf. la fiche licencié : voir la date du dernier mail évite la relance en double.
            'dernierContact' => $this->dernierContact->pour($dirigeant),
            // Détention et engagement de la saison en une lecture : la fiche affiche
            // ce que le registre des clés sait de cette personne, ou rien.
            'cleRow' => $this->clePresenter->pourDirigeant($dirigeant),
            // Documents attendus et leur signature éventuelle : la checklist n'est plus
            // une liste figée, elle suit ce que la saison demande à ce dirigeant.
            'documents' => $this->documentResolver->attendusPourDirigeant($dirigeant),
            'signatures' => $signatures,
            'dossierComplet' => $this->dossierCompletion->isComplete($dirigeant),
            'statut' => $this->statutResolver->pour($dirigeant),
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

    /** Le club a signé la licence dans FootClubs : dernier état du parcours. */
    #[Route('/{uuid}/valider-footclubs', name: 'validate_fff', methods: ['POST'])]
    public function validateFff(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
    ): Response {
        $this->csrf->valider('dirigeant_valider_fff_' . $dirigeant->getUuid(), $request);

        $this->dirigeantService->validerSurFootclubs($dirigeant);
        $this->addFlash('success', 'Licence de ' . $dirigeant->getNomPrenom() . ' validée.');

        return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
    }

    /** Sortie de secours d'un clic malheureux : la licence redevient à valider. */
    #[Route('/{uuid}/annuler-validation-footclubs', name: 'cancel_validate_fff', methods: ['POST'])]
    public function cancelValidateFff(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
    ): Response {
        $this->csrf->valider('dirigeant_annuler_valider_fff_' . $dirigeant->getUuid(), $request);

        $this->dirigeantService->annulerValidationFootclubs($dirigeant);
        $this->addFlash('success', 'Validation annulée : la licence est de nouveau à valider sur FootClubs.');

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

    #[Route('/{uuid}/coordonnees/{champ}/reprendre-import', name: 'contact_reprendre_import', methods: ['POST'])]
    public function reprendreImportContact(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        ChampContact $champ,
        Request $request,
    ): Response {
        $this->csrf->valider('dirigeant_contact_reprendre_import_' . $dirigeant->getUuid(), $request);

        $this->dirigeantService->reprendreImport($dirigeant, $champ);
        $this->addFlash('success', $champ->label() . ' sera de nouveau mis à jour par le prochain import FootClubs.');

        return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
    }
}

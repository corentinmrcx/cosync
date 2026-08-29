<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\ContactData;
use App\DTO\Effectif\ResultatSuppression;
use App\DTO\EnvoiGroupeResultat;
use App\DTO\FiltreListe;
use App\DTO\LicencieCreateData;
use App\DTO\LicencieIdentityData;
use App\DTO\PaiementManuelData;
use App\DTO\RelanceResultat;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ChampContact;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use App\Enum\PaymentMode;
use App\Form\ContactType;
use App\Form\LicencieCreateType;
use App\Form\LicencieEditType;
use App\Form\LicencieIdentityType;
use App\Repository\AttestationPaiementRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Repository\TransactionRepository;
use App\Security\CsrfGuard;
use App\Security\Voter\SuperAdminVoter;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Document\SignatureCompletionService;
use App\Service\Document\SignatureRelanceService;
use App\Service\Dotation\DotationSuiviPresenter;
use App\Service\Effectif\SuppressionFicheService;
use App\Service\Inscription\AutorisationCompletionService;
use App\Service\Licencie\FicheActionsResolver;
use App\Service\Licencie\HistoriqueFicheService;
use App\Service\Licencie\LicencieService;
use App\Service\Licencie\PaiementService;
use App\Service\Mail\InscriptionLinkService;
use App\Service\Payment\AttestationPaiementService;
use App\Service\Payment\CotisationResolver;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/effectif/joueurs', name: 'admin_licencies_')]
class LicencieController extends AbstractController
{
    public function __construct(
        private readonly ListFilterMemory $filterMemory,
        private readonly StockMovementRepository $stockMovementRepo,
        private readonly CotisationResolver $cotisationResolver,
        private readonly DotationSuiviPresenter $dotationSuivi,
        private readonly AutorisationCompletionService $completionService,
        private readonly LicencieRepository $licencieRepo,
        private readonly TeamRepository $teamRepo,
        private readonly LicencieService $licencieService,
        private readonly InscriptionLinkService $inscriptionLinkService,
        private readonly TransactionRepository $transactionRepo,
        private readonly CsrfGuard $csrf,
        private readonly PaiementService $paiementService,
        private readonly HistoriqueFicheService $historiqueService,
        private readonly FicheActionsResolver $ficheActions,
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly SignatureCompletionService $signatureCompletion,
        private readonly SignatureRelanceService $relanceService,
        private readonly SuppressionFicheService $suppressionService,
        private readonly AttestationPaiementService $attestationPaiement,
        private readonly AttestationPaiementRepository $attestationPaiementRepo,
    ) {}

    #[Route('', name: 'list')]
    public function list(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        // Le mode édition n'est pas un filtre : il n'est jamais mémorisé, sinon la liste
        // rouvrirait ses cases à cocher de suppression à la prochaine visite. Il est seulement
        // reporté sur la redirection de restauration des filtres, pour ne pas se perdre en route.
        $edition = $request->query->getBoolean('edition') && $this->isGranted(SuperAdminVoter::ACCES_DIAGNOSTIC);

        $restored = $this->filterMemory->restoreOrRemember('licencies', $request, ['team', 'status', 'nature', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_licencies_list', $edition ? $restored + ['edition' => 1] : $restored);
        }

        $currentTeam = null;
        $currentStatus = null;
        $currentNature = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $this->teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('status') && $request->query->get('status') !== '') {
            $currentStatus = LicenceStatus::tryFrom($request->query->get('status'));
        }
        if ($request->query->has('nature') && $request->query->get('nature') !== '') {
            $currentNature = NatureLicence::tryFrom($request->query->get('nature'));
        }

        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $total = $this->licencieRepo->countWithFilters($season, $currentTeam, null, $currentStatus, $search ?: null, $currentNature);
        $pages = (int) ceil($total / $perPage);

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
            FiltreListe::depuisEnum('status', 'Statut', 'Tous', LicenceStatus::cases(), $currentStatus),
            FiltreListe::depuisEnum('nature', 'Nature', 'Toutes', NatureLicence::cases(), $currentNature),
        ];

        return $this->render('admin/licencies/list.html.twig', [
            'licencies' => $this->licencieRepo->findWithFilters($season, $currentTeam, null, $currentStatus, $search ?: null, $currentNature, $perPage, $offset),
            'season' => $season,
            'search' => $search,
            'filterGroups' => $filterGroups,
            'activeFilterCount' => FiltreListe::compterActifs($filterGroups),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'liensEnAttente' => $this->licencieRepo->countLienJamaisEnvoye($season),
            // Signalé au même endroit que les liens jamais envoyés : c'est la même
            // question — qui reste-t-il à relancer avant que la saison démarre ?
            'signaturesEnAttente' => count($this->relanceService->licencies($season)),
            // Troisième relance du même écran, mais celle-ci ne s'adresse à personne :
            // c'est le club qui a une démarche à faire dans FootClubs.
            'aValiderFff' => $this->licencieRepo->countAValiderFff($season),
            'edition' => $edition,
        ]);
    }

    /**
     * Envoi groupé des liens d'inscription.
     *
     * L'import ne prévient plus personne de lui-même : un fichier déposé par erreur écrivait
     * jusqu'ici à tout un effectif avant même que le rapport soit lu, et un licencié importé
     * avant que les équipes existent recevait un montant de cotisation et une liste de
     * dotation faux. L'envoi est donc une décision, prise sur cet écran, après relecture.
     */
    #[Route('/envoyer-liens', name: 'send_links', methods: ['GET', 'POST'])]
    public function sendLinks(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $enAttente = $this->licencieRepo->findLienJamaisEnvoye($season);

        if ($request->isMethod('POST')) {
            $this->csrf->valider('envoyer_liens_licencies', $request);

            $resultat = $this->inscriptionLinkService->envoyerEnMasse(
                $enAttente,
                array_map(strval(...), $request->request->all('licencies')),
            );

            $this->addFlash(
                $resultat->envoyes > 0 ? 'success' : 'info',
                $this->resumeEnvoi($resultat),
            );

            return $this->redirectToRoute('admin_licencies_list');
        }

        $joignables = array_filter($enAttente, static fn (Licencie $l): bool => $l->getEmail() !== null);

        return $this->render('admin/licencies/envoyer_liens.html.twig', [
            'enAttente' => $enAttente,
            'sansEmail' => count($enAttente) - count($joignables),
            'sansEquipe' => count(array_filter($joignables, static fn (Licencie $l): bool => $l->getTeam() === null)),
            // Cochés d'office : ceux dont le formulaire dira la vérité. Un licencié sans équipe
            // annoncerait la cotisation par défaut de la saison — il reste dans la liste, à
            // cocher à la main si l'admin le veut quand même.
            'coches' => array_values(array_map(
                static fn (Licencie $l): string => (string) $l->getUuid(),
                array_filter($joignables, static fn (Licencie $l): bool => $l->getTeam() !== null),
            )),
            'joignables' => array_values(array_map(
                static fn (Licencie $l): string => (string) $l->getUuid(),
                $joignables,
            )),
        ]);
    }

    /** Le compte rendu nomme ce qui reste à faire à la main : sans email, aucun lien ne part. */
    private function resumeRelance(RelanceResultat $resultat, string $population): string
    {
        $resume = sprintf('%d lien%s envoyé%s', $resultat->envoyes, $resultat->envoyes > 1 ? 's' : '', $resultat->envoyes > 1 ? 's' : '');

        if ($resultat->sansEmail > 0) {
            $resume .= sprintf(', %d %s%s sans adresse email à prévenir autrement', $resultat->sansEmail, $population, $resultat->sansEmail > 1 ? 's' : '');
        }

        return $resume . '.';
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
     * Écran de confirmation du mode édition : il annonce, nom par nom, ce qui va être supprimé
     * et ce qui est refusé avec son motif. Déclaré avant `/{uuid}` : sans cela, « supprimer »
     * serait lu comme l'uuid d'une fiche.
     */
    /**
     * Relance groupée des signatures manquantes. Déclaré avant la route `/{uuid}` :
     * sans cela, « demander-signatures » serait lu comme un uuid.
     *
     * Cet écran vit ici, avec la population, et non dans « Documents à signer » : ce
     * dernier sert à préparer les documents, pas à relancer les gens. Frère de
     * « Envoyer les liens », dont il reprend le vocabulaire — cases à cocher comprises.
     */
    #[Route('/demander-signatures', name: 'request_signatures', methods: ['GET', 'POST'])]
    public function requestSignatures(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('demander_signatures_licencies', $request);

            $resultat = $this->relanceService->relancerLicencies(
                $season,
                array_map(strval(...), $request->request->all('personnes')),
            );

            $this->addFlash(
                $resultat->envoyes > 0 ? 'success' : 'info',
                $this->resumeRelance($resultat, 'joueur'),
            );

            return $this->redirectToRoute('admin_licencies_list');
        }

        $lignes = $this->relanceService->licencies($season);

        return $this->render('admin/effectif/demander_signatures.html.twig', [
            'lignes' => $lignes,
            'joignables' => $this->relanceService->uuidsJoignables($lignes),
            'population' => 'joueur',
            'tokenId' => 'demander_signatures_licencies',
            'actionUrl' => $this->generateUrl('admin_licencies_request_signatures'),
            'retourUrl' => $this->generateUrl('admin_licencies_list'),
            'intro' => 'Un joueur dont le dossier était déjà complet n\'a plus de lien valide : ajouter un document ne le prévient pas. Chaque personne cochée reçoit un lien rouvert pour 30 jours vers un parcours réduit à la signature — ni tailles, ni autorisations, ni paiement ne lui sont redemandés. Ceux qui n\'ont pas terminé leur inscription ne sont pas listés : leur formulaire leur présentera ces documents avec le reste.',
        ]);
    }

    /**
     * Validation groupée dans FootClubs. Déclaré avant la route `/{uuid}` : sans cela,
     * « valider-footclubs » serait lu comme un uuid.
     *
     * Le club signe ses licences dans FootClubs par paquets, puis vient le déclarer ici :
     * fiche par fiche, il faudrait rouvrir quarante écrans. La liste est repassée au crible
     * par le service — un uuid ajouté au formulaire ne peut pas valider une licence qui
     * n'était pas proposée.
     */
    #[Route('/valider-footclubs', name: 'validate_fff_bulk', methods: ['GET', 'POST'])]
    public function validateFffBulk(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $eligibles = $this->licencieRepo->findAValiderFff($season);

        if ($request->isMethod('POST')) {
            $this->csrf->valider('valider_footclubs_licencies', $request);

            $valides = $this->paiementService->validerSurFootclubsEnMasse(
                $eligibles,
                array_map(strval(...), $request->request->all('personnes')),
            );

            $this->addFlash(
                $valides > 0 ? 'success' : 'info',
                sprintf('%d licence%s validée%s.', $valides, $valides > 1 ? 's' : '', $valides > 1 ? 's' : ''),
            );

            return $this->redirectToRoute('admin_licencies_list');
        }

        return $this->render('admin/effectif/valider_footclubs.html.twig', [
            'personnes' => array_map(
                static fn (Licencie $l): array => [
                    'uuid' => (string) $l->getUuid(),
                    'nom' => $l->getNomPrenom(),
                    'detail' => $l->getTeam()?->getName() ?? $l->getCategory()->getLabel(),
                ],
                $eligibles,
            ),
            'population' => 'joueur',
            'tokenId' => 'valider_footclubs_licencies',
            'actionUrl' => $this->generateUrl('admin_licencies_validate_fff_bulk'),
            'retourUrl' => $this->generateUrl('admin_licencies_list'),
        ]);
    }

    #[Route('/supprimer', name: 'delete_confirm', methods: ['POST'])]
    #[IsGranted(SuperAdminVoter::ACCES_DIAGNOSTIC)]
    public function deleteConfirm(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $this->csrf->valider('supprimer_licencies', $request);

        $fiches = $this->licencieRepo->findByUuidsInSeason(
            array_map(strval(...), $request->request->all('licencies')),
            $season,
        );

        if ($fiches === []) {
            $this->addFlash('info', 'Aucune fiche sélectionnée.');

            return $this->redirectToRoute('admin_licencies_list', ['edition' => 1]);
        }

        return $this->render('admin/licencies/supprimer.html.twig', [
            'analyses' => $this->suppressionService->analyserLot($fiches),
        ]);
    }

    #[Route('/supprimer/confirmer', name: 'delete', methods: ['POST'])]
    #[IsGranted(SuperAdminVoter::ACCES_DIAGNOSTIC)]
    public function delete(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $this->csrf->valider('supprimer_licencies_confirmer', $request);

        $fiches = $this->licencieRepo->findByUuidsInSeason(
            array_map(strval(...), $request->request->all('licencies')),
            $season,
        );

        try {
            $resultat = $this->suppressionService->supprimerLot($fiches);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_licencies_list', ['edition' => 1]);
        }

        $this->addFlash(
            $resultat->supprimees > 0 ? 'success' : 'info',
            $this->resumeSuppression($resultat),
        );

        return $this->redirectToRoute('admin_licencies_list');
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
        $data = new LicencieCreateData();
        $form = $this->createForm(LicencieCreateType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $licencie = $this->licencieService->create($data, $season);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('admin/licencies/new.html.twig', ['form' => $form]);
            }

            if ($form->get('sendLink')->getData() && $licencie->getEmail() !== null) {
                try {
                    $this->inscriptionLinkService->send($licencie);
                    $this->addFlash('success', $licencie->getNomPrenom() . ' ajouté(e). Lien d\'inscription envoyé.');
                } catch (\Throwable) {
                    $this->addFlash('warning', $licencie->getNomPrenom() . ' ajouté(e), mais l\'envoi du mail a échoué. Vérifiez la configuration SMTP.');
                }
            } else {
                $this->addFlash('success', $licencie->getNomPrenom() . ' ajouté(e) avec succès.');
            }

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        return $this->render('admin/licencies/new.html.twig', ['form' => $form]);
    }

    #[Route('/{uuid}/identite', name: 'edit_identity', methods: ['GET', 'POST'])]
    public function editIdentity(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        if (!$licencie->isCreatedManually()) {
            throw $this->createAccessDeniedException('La correction d\'identité n\'est disponible que pour les licenciés créés manuellement.');
        }

        $data = new LicencieIdentityData();
        $data->nom = $licencie->getNom();
        $data->prenom = $licencie->getPrenom();
        $data->dateNaissance = $licencie->getDateNaissance();
        $data->category = $licencie->getCategory();
        $data->email = $licencie->getEmail();
        $data->telephone = $licencie->getTelephone();
        $data->voieRue = $licencie->getVoieRue();
        $data->codePostal = $licencie->getCodePostal();
        $data->ville = $licencie->getVille();
        $data->numLicence = $licencie->getNumLicence();

        $form = $this->createForm(LicencieIdentityType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->licencieService->editIdentity($licencie, $data);
                $this->addFlash('success', 'Identité de ' . $licencie->getNomPrenom() . ' mise à jour.');

                return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/licencies/identity.html.twig', [
            'form' => $form,
            'licencie' => $licencie,
        ]);
    }

    /**
     * Ouvert à tous, importés compris : l'identité FFF reste la propriété de FootClubs, mais
     * une adresse mail fausse doit pouvoir se corriger tout de suite — le lien d'inscription
     * en dépend, et l'export ne se corrige parfois qu'après validation du dossier à la ligue.
     */
    #[Route('/{uuid}/coordonnees', name: 'edit_contact', methods: ['GET', 'POST'])]
    public function editContact(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $data = new ContactData();
        $data->email = $licencie->getEmail();
        $data->telephone = $licencie->getTelephone();

        $form = $this->createForm(ContactType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licencieService->editContact($licencie, $data);
            $this->addFlash('success', 'Coordonnées de ' . $licencie->getNomPrenom() . ' mises à jour.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        return $this->render('admin/licencies/contact.html.twig', [
            'form' => $form,
            'licencie' => $licencie,
        ]);
    }

    #[Route('/{uuid}/coordonnees/{champ}/reprendre-import', name: 'contact_reprendre_import', methods: ['POST'])]
    public function reprendreImportContact(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        ChampContact $champ,
        Request $request,
    ): Response {
        $this->csrf->valider('contact_reprendre_import_' . $licencie->getUuid(), $request);

        $this->licencieService->reprendreImport($licencie, $champ);
        $this->addFlash('success', $champ->label() . ' sera de nouveau mis à jour par le prochain import FootClubs.');

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
    ): Response {
        // La fiche se lit dans la saison du licencié, jamais dans celle sélectionnée par
        // l'admin : chaque compte travaille dans la saison de son choix, et une fiche
        // s'ouvre par UUID (favori, lien de mail) sans passer par la liste filtrée.
        // Prendre la saison de l'admin masquerait les paiements du licencié.
        $season = $licencie->getSeason();
        $transactions = $this->transactionRepo->findAllByLicencieAndSeason($licencie, $season);
        $totalPaid = $this->transactionRepo->sumByLicencieAndSeason($licencie, $season);

        $montant = $this->cotisationResolver->resolve($licencie);
        $remainingAmount = max(0, (float) $montant - $totalPaid);

        $autorisationsManquantes = $this->completionService->hasMissing($licencie);
        $signatureManquante = $this->signatureCompletion->hasMissing($licencie);
        $attestationBlocage = $this->attestationPaiement->motifBlocage($licencie);

        return $this->render('admin/licencies/show.html.twig', [
            'licencie' => $licencie,
            'transactions' => $transactions,
            'totalPaid' => $totalPaid,
            'remainingAmount' => $remainingAmount,
            'season' => $season,
            'montant' => $montant,
            'paymentModes' => PaymentMode::proposables(),
            'paymentModesAvecReference' => PaymentMode::valeursAvecReference(),
            'dotations' => $this->stockMovementRepo->findDotationsByLicencie($licencie),
            'dotationStatut' => $this->dotationSuivi->avancementDe($licencie),
            'history' => $this->historiqueService->pourLicencie($licencie, $transactions),
            'autorisationsManquantes' => $autorisationsManquantes,
            // Documents attendus et leur signature éventuelle : la checklist n'est plus
            // une liste figée, elle suit ce que la saison demande.
            'documents' => $this->documentResolver->attendusPourLicencie($licencie),
            'signatures' => $this->documentResolver->signaturesParDocumentPourLicencie($licencie),
            // Un document ajouté depuis l'inscription : le dossier est complet, son lien
            // est consommé, rien ne le lui redemanderait sans ce bouton.
            'signatureManquante' => $signatureManquante,
            // Attestations de paiement déjà émises, et ce qui empêche d'en émettre une
            // nouvelle — le motif est affiché plutôt que le bouton simplement masqué :
            // « rien ne s'affiche » n'apprend rien à l'admin qui cherche le bouton.
            'attestations' => $this->attestationPaiementRepo->findByLicencie($licencie),
            'attestationBlocage' => $attestationBlocage,
            // Une action mise en avant, les autres dans un menu : l'en-tête en alignait
            // jusqu'à cinq, dont trois en « bouton principal ».
            'actions' => $this->ficheActions->pour(
                $licencie,
                autorisationsManquantes: $autorisationsManquantes,
                signatureManquante: $signatureManquante,
                attestationPossible: $attestationBlocage === null,
            ),
        ]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $dossier = $licencie->getDossierClub();

        $form = $this->createForm(LicencieEditType::class, $licencie, [
            // Saison du licencié : le sélecteur d'équipe ne doit proposer que les équipes
            // de sa saison, pas celles de la saison sélectionnée par l'admin.
            'season' => $licencie->getSeason(),
            'taille_haut' => $dossier?->getTailleHaut(),
            'taille_bas' => $dossier?->getTailleBas(),
            'pointure' => $dossier?->getPointure(),
            'nature_licence' => $licencie->getNatureLicence(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licencieService->edit(
                $licencie,
                $form->get('tailleHaut')->getData() ?: null,
                $form->get('tailleBas')->getData() ?: null,
                $form->get('pointure')->getData() ?: null,
                $form->get('natureLicence')->getData(),
            );

            $this->addFlash('success', 'Dossier de ' . $licencie->getNomPrenom() . ' mis à jour.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        return $this->render('admin/licencies/edit.html.twig', [
            'form' => $form,
            'licencie' => $licencie,
        ]);
    }

    #[Route('/{uuid}/ajouter-paiement', name: 'add_payment', methods: ['POST'])]
    public function addPayment(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        $this->csrf->valider('add_payment_' . $licencie->getUuid(), $request);

        try {
            $paiement = PaiementManuelData::fromRequest($request);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        $this->paiementService->enregistrer(
            $licencie,
            $paiement->mode,
            $paiement->montant,
            $paiement->reference,
            $paiement->note,
            $paiement->datePaiement,
            $user,
            // Saison du licencié, pas celle de l'admin : sinon un dirigeant resté sur une
            // autre saison rattache l'encaissement au mauvais exercice, le solde n'est
            // jamais atteint et la licence ne passe pas en VALIDATED.
            $licencie->getSeason(),
        );

        $this->addFlash('success', 'Paiement de ' . $licencie->getNomPrenom() . ' enregistré.');

        $estSolde = $licencie->getDossierClub()?->estSoldee() === true;
        $params = ['uuid' => $licencie->getUuid()];
        if (!$estSolde) {
            $params['paymentsModal'] = '1';
        }

        return $this->redirectToRoute('admin_licencies_show', $params);
    }

    #[Route('/{uuid}/paiements/{id}/supprimer', name: 'delete_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePayment(
        string $uuid,
        int $id,
        Request $request,
    ): Response {
        $this->csrf->valider('delete_payment_' . $id, $request);

        $transaction = $this->transactionRepo->find($id);
        if ($transaction === null || (string) $transaction->getLicencie()->getUuid() !== $uuid) {
            throw $this->createNotFoundException('Paiement introuvable.');
        }

        $this->paiementService->supprimer($transaction);
        $this->addFlash('success', 'Paiement supprimé.');

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid, 'paymentsModal' => '1']);
    }

    #[Route('/{uuid}/valider-manuellement', name: 'validate_manually', methods: ['POST'])]
    public function validateManually(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $this->csrf->valider('validate_manually_' . $licencie->getUuid(), $request);

        $this->paiementService->validerManuellement($licencie);

        $this->addFlash('success', 'Licence de ' . $licencie->getNomPrenom() . ' considérée comme payée.');

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    /** Le club a signé la licence dans FootClubs : dernier statut du parcours. */
    #[Route('/{uuid}/valider-footclubs', name: 'validate_fff', methods: ['POST'])]
    public function validateFff(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $this->csrf->valider('valider_fff_' . $licencie->getUuid(), $request);

        try {
            $this->paiementService->validerSurFootclubs($licencie);
            $this->addFlash('success', 'Licence de ' . $licencie->getNomPrenom() . ' validée.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    /** Sortie de secours d'un clic malheureux : la licence redevient « à valider ». */
    #[Route('/{uuid}/annuler-validation-footclubs', name: 'cancel_validate_fff', methods: ['POST'])]
    public function cancelValidateFff(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $this->csrf->valider('annuler_valider_fff_' . $licencie->getUuid(), $request);

        try {
            $this->paiementService->annulerValidationFootclubs($licencie);
            $this->addFlash('success', 'Validation annulée : la licence est de nouveau à valider sur FootClubs.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    #[Route('/{uuid}/demander-signature', name: 'request_signature', methods: ['POST'])]
    public function requestSignature(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $this->csrf->valider('request_signature_' . $licencie->getUuid(), $request);

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        if (!$this->signatureCompletion->hasMissing($licencie)) {
            $this->addFlash('error', 'Aucun document en attente de signature pour ce licencié.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        try {
            $this->inscriptionLinkService->sendSignature($licencie);
            $this->addFlash('success', 'Demande de signature envoyée à ' . $licencie->getEmail() . '.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    #[Route('/{uuid}/send-link', name: 'send_link', methods: ['POST'])]
    public function sendLink(#[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie, Request $request): Response
    {
        $this->csrf->valider('send_link_' . $licencie->getUuid(), $request);

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        try {
            $this->inscriptionLinkService->send($licencie);
            $this->addFlash('success', 'Lien d\'inscription envoyé à ' . $licencie->getEmail() . '.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }

    #[Route('/{uuid}/send-completion', name: 'send_completion', methods: ['POST'])]
    public function sendCompletion(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $this->csrf->valider('send_completion_' . $licencie->getUuid(), $request);

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        if (!$this->completionService->hasMissing($licencie)) {
            $this->addFlash('error', 'Aucune autorisation manquante pour ce licencié.');

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        try {
            $this->inscriptionLinkService->sendCompletion($licencie);
            $this->addFlash('success', 'Lien de complétion envoyé à ' . $licencie->getEmail() . '.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
    }
}

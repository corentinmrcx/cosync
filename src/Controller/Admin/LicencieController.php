<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\FiltreListe;
use App\DTO\LicencieCreateData;
use App\DTO\LicencieIdentityData;
use App\DTO\PaiementManuelData;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use App\Enum\PaymentMode;
use App\Form\LicencieCreateType;
use App\Form\LicencieEditType;
use App\Form\LicencieIdentityType;
use App\Repository\LicencieRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Repository\TransactionRepository;
use App\Security\CsrfGuard;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Dotation\DotationSuiviPresenter;
use App\Service\Inscription\AutorisationCompletionService;
use App\Service\Licencie\HistoriqueFicheService;
use App\Service\Licencie\LicencieService;
use App\Service\Licencie\PaiementService;
use App\Service\Mail\InscriptionLinkService;
use App\Service\Payment\CotisationResolver;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

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
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    #[Route('', name: 'list')]
    public function list(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $restored = $this->filterMemory->restoreOrRemember('licencies', $request, ['team', 'status', 'nature', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_licencies_list', $restored);
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
        ]);
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
            'autorisationsManquantes' => $this->completionService->hasMissing($licencie),
            // Documents attendus et leur signature éventuelle : la checklist n'est plus
            // une liste figée, elle suit ce que la saison demande.
            'documents' => $this->documentResolver->attendusPourLicencie($licencie),
            'signatures' => $this->documentResolver->signaturesParDocumentPourLicencie($licencie),
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

        $isValidated = $licencie->getDossierClub()?->getStatus() === LicenceStatus::VALIDATED;
        $params = ['uuid' => $licencie->getUuid()];
        if (!$isValidated) {
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

        $this->addFlash('success', 'Licence de ' . $licencie->getNomPrenom() . ' validée manuellement.');

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

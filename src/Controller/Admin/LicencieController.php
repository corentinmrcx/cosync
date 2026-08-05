<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\LicencieCreateData;
use App\DTO\LicencieIdentityData;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use App\Enum\PaymentMode;
use App\Form\LicencieCreateType;
use App\Form\LicencieEditType;
use App\Form\LicencieIdentityType;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Repository\TransactionRepository;
use App\Service\LicencieService;
use App\Service\Mail\InscriptionLinkService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/licencies', name: 'admin_licencies_')]
class LicencieController extends AbstractController
{
    #[Route('', name: 'list')]
    public function list(
        Request $request,
        LicencieRepository $licencieRepo,
        SeasonContext $seasonContext,
        TeamRepository $teamRepo,
        \App\Service\ListFilterMemory $filterMemory,
    ): Response {
        $season = $seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $restored = $filterMemory->restoreOrRemember('licencies', $request, ['team', 'status', 'nature', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_licencies_list', $restored);
        }

        $currentTeam   = null;
        $currentStatus = null;
        $currentNature = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('status') && $request->query->get('status') !== '') {
            $currentStatus = LicenceStatus::tryFrom($request->query->get('status'));
        }
        if ($request->query->has('nature') && $request->query->get('nature') !== '') {
            $currentNature = NatureLicence::tryFrom($request->query->get('nature'));
        }

        $search  = trim((string) $request->query->get('search', ''));
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $total = $licencieRepo->countWithFilters($season, $currentTeam, null, $currentStatus, $search ?: null, $currentNature);
        $pages = (int) ceil($total / $perPage);

        $teams = $teamRepo->findBySeason($season);

        $filterGroups = [
            [
                'name'     => 'team',
                'label'    => 'Équipe',
                'allLabel' => 'Toutes',
                'options'  => array_map(fn($t) => ['value' => $t->getId(), 'label' => $t->getName()], $teams),
                'current'  => $currentTeam?->getId(),
            ],
            [
                'name'     => 'status',
                'label'    => 'Statut',
                'allLabel' => 'Tous',
                'options'  => array_map(fn(LicenceStatus $s) => ['value' => $s->value, 'label' => $s->label()], LicenceStatus::cases()),
                'current'  => $currentStatus?->value,
            ],
            [
                'name'     => 'nature',
                'label'    => 'Nature',
                'allLabel' => 'Toutes',
                'options'  => array_map(fn(NatureLicence $n) => ['value' => $n->value, 'label' => $n->label()], NatureLicence::cases()),
                'current'  => $currentNature?->value,
            ],
        ];

        return $this->render('admin/licencies/list.html.twig', [
            'licencies'         => $licencieRepo->findWithFilters($season, $currentTeam, null, $currentStatus, $search ?: null, $currentNature, $perPage, $offset),
            'season'            => $season,
            'search'            => $search,
            'filterGroups'      => $filterGroups,
            'activeFilterCount' => ($currentTeam ? 1 : 0) + ($currentStatus ? 1 : 0) + ($currentNature ? 1 : 0),
            'total'             => $total,
            'page'              => $page,
            'pages'             => $pages,
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        SeasonContext $seasonContext,
        LicencieService $licencieService,
        InscriptionLinkService $inscriptionLinkService,
    ): Response {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $data = new LicencieCreateData();
        $form = $this->createForm(LicencieCreateType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $licencie = $licencieService->create($data, $season);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->render('admin/licencies/new.html.twig', ['form' => $form]);
            }

            if ($form->get('sendLink')->getData() && $licencie->getEmail() !== null) {
                try {
                    $inscriptionLinkService->send($licencie);
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
        string $uuid,
        Request $request,
        LicencieRepository $licencieRepo,
        LicencieService $licencieService,
    ): Response {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));
        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        if (!$licencie->isCreatedManually()) {
            throw $this->createAccessDeniedException('La correction d\'identité n\'est disponible que pour les licenciés créés manuellement.');
        }

        $data = new LicencieIdentityData();
        $data->nom          = $licencie->getNom();
        $data->prenom       = $licencie->getPrenom();
        $data->dateNaissance = $licencie->getDateNaissance();
        $data->category     = $licencie->getCategory();
        $data->email        = $licencie->getEmail();
        $data->telephone    = $licencie->getTelephone();
        $data->voieRue      = $licencie->getVoieRue();
        $data->codePostal   = $licencie->getCodePostal();
        $data->ville        = $licencie->getVille();
        $data->numLicence   = $licencie->getNumLicence();

        $form = $this->createForm(LicencieIdentityType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $licencieService->editIdentity($licencie, $data);
                $this->addFlash('success', 'Identité de ' . $licencie->getNomPrenom() . ' mise à jour.');
                return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/licencies/identity.html.twig', [
            'form'     => $form,
            'licencie' => $licencie,
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        string $uuid,
        LicencieRepository $licencieRepo,
        TransactionRepository $transactionRepo,
        \App\Repository\StockMovementRepository $stockMovementRepo,
        SeasonContext $seasonContext,
        \App\Service\CotisationResolver $cotisationResolver,
        \App\Service\Stock\DotationBesoinService $dotationBesoinService,
        \App\Service\Form\AutorisationCompletionService $completionService,
    ): Response {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        $season       = $seasonContext->getCurrentSeason();
        $transactions = $season ? $transactionRepo->findAllByLicencieAndSeason($licencie, $season) : [];
        $totalPaid    = $season ? $transactionRepo->sumByLicencieAndSeason($licencie, $season) : 0.0;

        $montant         = $cotisationResolver->resolve($licencie);
        $remainingAmount = max(0, (float) $montant - $totalPaid);

        $history = [
            [
                'date'   => $licencie->getImportedAt(),
                'format' => 'd/m/Y à H:i',
                'label'  => $licencie->isCreatedManually()
                    ? 'Licencié créé manuellement'
                    : 'Licencié importé depuis FootClubs',
                'who'    => 'Admin',
            ],
        ];

        if ($licencie->getLinkSentAt() !== null) {
            $history[] = ['date' => $licencie->getLinkSentAt(), 'format' => 'd/m/Y à H:i', 'label' => 'Lien d\'inscription envoyé par email', 'who' => 'Système'];
        }

        $dossier = $licencie->getDossierClub();
        if ($dossier?->getFormCompletedAt() !== null) {
            $history[] = ['date' => $dossier->getFormCompletedAt(), 'format' => 'd/m/Y à H:i', 'label' => 'Formulaire complété par le licencié', 'who' => 'Licencié'];
        }

        foreach ($transactions as $t) {
            $history[] = [
                'date'   => $t->getDatePaiement(),
                'format' => 'd/m/Y',
                'label'  => sprintf('Paiement enregistré — %s %s €', $t->getMode()->label(), $t->getMontant()),
                // Pas de dirigeant sur un encaissement en ligne : c'est HelloAsso qui l'a confirmé.
                'who'    => $t->getConfirmedBy()?->getEmail() ?? ($t->getMode() === PaymentMode::CB_ONLINE ? 'HelloAsso' : 'Admin'),
            ];
        }

        usort($history, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $this->render('admin/licencies/show.html.twig', [
            'licencie'        => $licencie,
            'transactions'    => $transactions,
            'totalPaid'       => $totalPaid,
            'remainingAmount' => $remainingAmount,
            'season'          => $season,
            'montant'         => $montant,
            'paymentModes'    => PaymentMode::cases(),
            'dotations'       => $stockMovementRepo->findDotationsByLicencie($licencie),
            'dotationStatut'  => $dotationBesoinService->statutFicheLicencie($licencie),
            'history'         => $history,
            'autorisationsManquantes' => $completionService->hasMissing($licencie),
        ]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        LicencieRepository $licencieRepo,
        SeasonContext $seasonContext,
        LicencieService $licencieService,
    ): Response {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));
        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $dossier = $licencie->getDossierClub();

        $form = $this->createForm(LicencieEditType::class, $licencie, [
            'season'         => $season,
            'taille_haut'    => $dossier?->getTailleHaut(),
            'taille_bas'     => $dossier?->getTailleBas(),
            'pointure'       => $dossier?->getPointure(),
            'nature_licence' => $licencie->getNatureLicence(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $licencieService->edit(
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
            'form'     => $form,
            'licencie' => $licencie,
        ]);
    }

    #[Route('/{uuid}/ajouter-paiement', name: 'add_payment', methods: ['POST'])]
    public function addPayment(
        string $uuid,
        Request $request,
        LicencieRepository $licencieRepo,
        SeasonContext $seasonContext,
        LicencieService $licencieService,
    ): Response {
        if (!$this->isCsrfTokenValid('add_payment_' . $uuid, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));
        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            $this->addFlash('error', 'Aucune saison sélectionnée.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        $mode    = PaymentMode::tryFrom($request->request->get('mode', ''));
        $montant = (float) str_replace(',', '.', $request->request->get('montant', '0'));
        $dateRaw = $request->request->get('date_paiement', '');

        if ($mode === null || $montant <= 0 || $dateRaw === '') {
            $this->addFlash('error', 'Mode, montant ou date invalide.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        try {
            $date = new \DateTimeImmutable($dateRaw);
        } catch (\Exception) {
            $this->addFlash('error', 'Date invalide.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        $licencieService->addPayment(
            $licencie,
            $mode,
            $montant,
            $request->request->get('reference') ?: null,
            $request->request->get('note') ?: null,
            $date,
            $this->getUser(),
            $season,
        );

        $this->addFlash('success', 'Paiement de ' . $licencie->getNomPrenom() . ' enregistré.');

        $isValidated = $licencie->getDossierClub()?->getStatus() === LicenceStatus::VALIDATED;
        $params = ['uuid' => $uuid];
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
        TransactionRepository $transactionRepo,
        LicencieService $licencieService,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_payment_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $transaction = $transactionRepo->find($id);
        if ($transaction === null || (string) $transaction->getLicencie()->getUuid() !== $uuid) {
            throw $this->createNotFoundException('Paiement introuvable.');
        }

        $licencieService->deletePayment($transaction);
        $this->addFlash('success', 'Paiement supprimé.');

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid, 'paymentsModal' => '1']);
    }

    #[Route('/{uuid}/valider-manuellement', name: 'validate_manually', methods: ['POST'])]
    public function validateManually(
        string $uuid,
        Request $request,
        LicencieRepository $licencieRepo,
        LicencieService $licencieService,
    ): Response {
        if (!$this->isCsrfTokenValid('validate_manually_' . $uuid, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));
        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        $licencieService->validateManually($licencie);

        $this->addFlash('success', 'Licence de ' . $licencie->getNomPrenom() . ' validée manuellement.');
        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/send-link', name: 'send_link', methods: ['POST'])]
    public function sendLink(string $uuid, LicencieRepository $licencieRepo, InscriptionLinkService $inscriptionLinkService, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('send_link_' . $uuid, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        try {
            $inscriptionLinkService->send($licencie);
            $this->addFlash('success', 'Lien d\'inscription envoyé à ' . $licencie->getEmail() . '.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/send-completion', name: 'send_completion', methods: ['POST'])]
    public function sendCompletion(
        string $uuid,
        LicencieRepository $licencieRepo,
        InscriptionLinkService $inscriptionLinkService,
        \App\Service\Form\AutorisationCompletionService $completionService,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('send_completion_' . $uuid, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        if (!$completionService->hasMissing($licencie)) {
            $this->addFlash('error', 'Aucune autorisation manquante pour ce licencié.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        try {
            $inscriptionLinkService->sendCompletion($licencie);
            $this->addFlash('success', 'Lien de complétion envoyé à ' . $licencie->getEmail() . '.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
    }
}

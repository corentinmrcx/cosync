<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Form\LicencieEditType;
use App\Repository\CategoryRepository;
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
        CategoryRepository $categoryRepo,
    ): Response {
        $season = $seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $currentTeam     = null;
        $currentCategory = null;
        $currentStatus   = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('category') && $request->query->get('category') !== '') {
            $currentCategory = $categoryRepo->find((int) $request->query->get('category'));
        }
        if ($request->query->has('status') && $request->query->get('status') !== '') {
            $currentStatus = LicenceStatus::tryFrom($request->query->get('status'));
        }

        $search  = trim((string) $request->query->get('search', ''));
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $total = $licencieRepo->countWithFilters($season, $currentTeam, $currentCategory, $currentStatus, $search ?: null);
        $pages = (int) ceil($total / $perPage);

        return $this->render('admin/licencies/list.html.twig', [
            'licencies'       => $licencieRepo->findWithFilters($season, $currentTeam, $currentCategory, $currentStatus, $search ?: null, $perPage, $offset),
            'season'          => $season,
            'teams'           => $teamRepo->findBySeason($season),
            'categories'      => $categoryRepo->findAll(),
            'statuses'        => LicenceStatus::cases(),
            'currentTeam'     => $currentTeam,
            'currentCategory' => $currentCategory,
            'currentStatus'   => $currentStatus,
            'search'          => $search,
            'total'           => $total,
            'page'            => $page,
            'pages'           => $pages,
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        string $uuid,
        LicencieRepository $licencieRepo,
        TransactionRepository $transactionRepo,
        SeasonContext $seasonContext,
    ): Response {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        $season      = $seasonContext->getCurrentSeason();
        $transaction = $season ? $transactionRepo->findByLicencieAndSeason($licencie, $season) : null;

        return $this->render('admin/licencies/show.html.twig', [
            'licencie'    => $licencie,
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        LicencieRepository $licencieRepo,
        TransactionRepository $transactionRepo,
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

        $dossier     = $licencie->getDossierClub();
        $transaction = $transactionRepo->findByLicencieAndSeason($licencie, $season);

        $form = $this->createForm(LicencieEditType::class, $licencie, [
            'season'            => $season,
            'taille_haut'       => $dossier?->getTailleHaut(),
            'taille_bas'        => $dossier?->getTailleBas(),
            'pointure'          => $dossier?->getPointure(),
            'payment_mode'      => $transaction?->getMode(),
            'payment_montant'   => $transaction?->getMontant(),
            'payment_reference' => $transaction?->getReference(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $paymentModeValue = $form->get('paymentMode')->getData();
            $paymentMode      = $paymentModeValue ? PaymentMode::tryFrom($paymentModeValue) : null;
            $montant          = $form->get('paymentMontant')->getData();

            $licencieService->edit(
                $licencie,
                $form->get('tailleHaut')->getData() ?: null,
                $form->get('tailleBas')->getData() ?: null,
                $form->get('pointure')->getData() ?: null,
                $paymentMode,
                is_numeric($montant) ? (float) $montant : null,
                $form->get('paymentReference')->getData() ?: null,
                $this->getUser(),
                $season,
            );

            $this->addFlash('success', 'Dossier de ' . $licencie->getNomPrenom() . ' mis à jour.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        return $this->render('admin/licencies/edit.html.twig', [
            'form'        => $form,
            'licencie'    => $licencie,
            'transaction' => $transaction,
        ]);
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
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors de l\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
    }
}

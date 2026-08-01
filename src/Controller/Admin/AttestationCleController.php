<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\ClubHouse\AttestationCleRecapService;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationClePdfService;
use App\Service\Pdf\AttestationCleRecapPdfService;
use App\Service\SeasonContext;
use App\Service\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition du texte de l'attestation de remise de clés et exports associés.
 * Le texte est porté par la saison courante : le multi-saisons passe par le
 * sélecteur de saison de la navbar.
 */
#[Route('/admin/club-house/attestation', name: 'admin_clubhouse_attestation_')]
class AttestationCleController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
    ) {}

    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SeasonService $seasonService): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('attestation_cle_text_' . $season->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expirée, veuillez réessayer.');

                return $this->redirectToRoute('admin_clubhouse_attestation_edit');
            }

            $seasonService->updateAttestationCleText($season, $request->request->get('attestation_cle_text') ?: null);
            $this->addFlash('success', 'Attestation enregistrée.');

            return $this->redirectToRoute('admin_clubhouse_attestation_edit');
        }

        return $this->render('admin/clubhouse/attestation.html.twig', [
            'season' => $season,
        ]);
    }

    #[Route('/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(AttestationClePdfService $pdfService): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        return new Response($pdfService->generatePreview($season), Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="attestation_cle_apercu.pdf"',
        ]);
    }

    #[Route('/recapitulatif', name: 'recap', methods: ['GET'])]
    public function recap(AttestationCleRecapService $recapService, AttestationCleRecapPdfService $pdfService): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $pdf = $pdfService->generate($season, $recapService->buildRows($season));

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="detenteurs_cles_%s.pdf"',
                str_replace('/', '-', $season->getLabel()),
            ),
        ]);
    }

    #[Route('/recapitulatif/synchroniser', name: 'recap_sync', methods: ['POST'])]
    public function recapSync(Request $request, PendingUploadQueue $queue): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        if (!$this->isCsrfTokenValid('attestation_cle_recap_sync', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('admin_clubhouse_attestation_edit');
        }

        $queue->enqueueAttestationCleRecap($season->getId());
        $this->addFlash('success', 'Le récapitulatif sera régénéré sur Drive dans quelques secondes.');

        return $this->redirectToRoute('admin_clubhouse_attestation_edit');
    }
}

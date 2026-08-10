<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Security\CsrfGuard;
use App\Service\Cle\AttestationCleRecapService;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Pdf\AttestationClePdfService;
use App\Service\Pdf\AttestationCleRecapPdfService;
use App\Service\Saison\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition du texte de l'attestation de remise de clés et exports associés.
 * Le texte est porté par la saison courante : le multi-saisons passe par le
 * sélecteur de saison de la navbar.
 */
#[Route('/admin/cles/attestation', name: 'admin_cles_attestation_')]
class AttestationCleController extends AbstractController
{
    public function __construct(
        private readonly SeasonService $seasonService,
        private readonly AttestationClePdfService $apercuPdfService,
        private readonly AttestationCleRecapPdfService $recapPdfService,
        private readonly AttestationCleRecapService $recapService,
        private readonly PendingUploadQueue $queue,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentSeason] Season $season): Response
    {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('attestation_cle_text_' . $season->getId(), $request);

            $this->seasonService->updateAttestationCleText($season, $request->request->get('attestation_cle_text') ?: null);
            $this->addFlash('success', 'Attestation enregistrée.');

            return $this->redirectToRoute('admin_cles_attestation_edit');
        }

        return $this->render('admin/cles/attestation.html.twig', [
            'season' => $season,
        ]);
    }

    #[Route('/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(#[CurrentSeason] Season $season): Response
    {
        return new Response($this->apercuPdfService->generatePreview($season), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="attestation_cle_apercu.pdf"',
        ]);
    }

    #[Route('/recapitulatif', name: 'recap', methods: ['GET'])]
    public function recap(#[CurrentSeason] Season $season): Response
    {
        $pdf = $this->recapPdfService->generate($season, $this->recapService->buildRows($season));

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="detenteurs_cles_%s.pdf"',
                str_replace('/', '-', $season->getLabel()),
            ),
        ]);
    }

    #[Route('/recapitulatif/synchroniser', name: 'recap_sync', methods: ['POST'])]
    public function recapSync(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('attestation_cle_recap_sync', $request);

        $this->queue->enqueueAttestationCleRecap($season->getId());
        $this->addFlash('success', 'Le récapitulatif sera régénéré sur Drive dans quelques secondes.');

        return $this->redirectToRoute('admin_cles_attestation_edit');
    }
}

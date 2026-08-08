<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AttestationCleSignatureData;
use App\Repository\DirigeantRepository;
use App\Security\CsrfGuard;
use App\Service\AttestationCleFormService;
use App\Service\ClubHouse\CleRegistreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Signature publique de l'attestation de remise de clés, réservée aux détenteurs
 * de clés. Parcours autonome : son token est indépendant de celui du dossier dirigeant.
 */
#[Route('/attestation-cle', name: 'public_attestation_cle_')]
class AttestationCleController extends AbstractController
{
    public function __construct(
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly CleRegistreService $registre,
        private readonly AttestationCleFormService $formService,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(string $uuid): Response
    {
        $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            return $this->render('public/attestation_cle/expired.html.twig');
        }

        $detention = $this->registre->getDetentionDe($dirigeant);

        // Déjà signé ET toujours exact : rien à resigner.
        if ($detention->attestationAJour()) {
            return $this->redirectToRoute('public_attestation_cle_confirmation', ['uuid' => $uuid]);
        }

        if (!$dirigeant->isAttestationCleTokenValid()) {
            return $this->render('public/attestation_cle/expired.html.twig');
        }

        return $this->render('public/attestation_cle/form.html.twig', [
            'dirigeant' => $dirigeant,
            'nbCles' => $detention->solde,
            'remiseLe' => $detention->detenteurDepuis,
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function submit(
        string $uuid,
        Request $request,
    ): Response {
        $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || !$dirigeant->isAttestationCleTokenValid()) {
            return $this->render('public/attestation_cle/expired.html.twig');
        }

        $this->csrf->valider('attestation_cle_submit', $request);

        $data = $this->buildSignatureData($request);

        if ($data === null) {
            $this->addFlash('error', 'Signature manquante, veuillez signer avant de valider.');

            return $this->redirectToRoute('public_attestation_cle_show', ['uuid' => $uuid]);
        }

        $this->formService->submit($dirigeant, $data);

        return $this->redirectToRoute('public_attestation_cle_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function confirmation(string $uuid): Response
    {
        $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || $dirigeant->getAttestationCleSignedAt() === null) {
            return $this->render('public/attestation_cle/expired.html.twig');
        }

        return $this->render('public/attestation_cle/confirmation.html.twig', [
            'dirigeant' => $dirigeant,
        ]);
    }

    /** Validation serveur : la présence d'une signature valide est la seule preuve retenue. */
    private function buildSignatureData(Request $request): ?AttestationCleSignatureData
    {
        $signatureData = (string) $request->request->get('signature_data', '');

        if ($signatureData === ''
            || !str_starts_with($signatureData, 'data:image/')
            || strlen($signatureData) > 2_800_000) {
            return null;
        }

        return new AttestationCleSignatureData(signatureData: $signatureData);
    }
}

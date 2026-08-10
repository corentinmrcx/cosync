<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AttestationCleSignatureData;
use App\Entity\AttestationCle;
use App\Repository\AttestationCleRepository;
use App\Security\CsrfGuard;
use App\Service\Cle\AttestationCleFormService;
use App\Service\Cle\CleRegistreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Signature publique de l'attestation de remise de clés, réservée aux détenteurs.
 * Parcours autonome : le lien porte l'attestation d'une saison, pas la personne —
 * un lien de l'an dernier ne vaut donc jamais pour l'engagement de cette année.
 */
#[Route('/attestation-cle', name: 'public_attestation_cle_')]
class AttestationCleController extends AbstractController
{
    public function __construct(
        private readonly AttestationCleRepository $attestationRepo,
        private readonly CleRegistreService $registre,
        private readonly AttestationCleFormService $formService,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(string $uuid): Response
    {
        $attestation = $this->trouver($uuid);

        if ($attestation === null) {
            return $this->lienInvalide();
        }

        if ($attestation->estSignee()) {
            return $this->redirectToRoute('public_attestation_cle_confirmation', ['uuid' => $uuid]);
        }

        if (!$attestation->isTokenValid()) {
            return $this->lienInvalide();
        }

        $detention = $this->registre->getDetentionDe($attestation->getDetenteur());

        return $this->render('public/attestation_cle/form.html.twig', [
            'attestation' => $attestation,
            'detenteur' => $attestation->getDetenteur(),
            'season' => $attestation->getSeason(),
            'nbCles' => $detention->solde,
            'remiseLe' => $detention->detenteurDepuis,
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function submit(string $uuid, Request $request): Response
    {
        $attestation = $this->trouver($uuid);

        if ($attestation === null || $attestation->estSignee() || !$attestation->isTokenValid()) {
            return $this->lienInvalide();
        }

        $this->csrf->valider('attestation_cle_submit', $request);

        $data = $this->buildSignatureData($request);

        if ($data === null) {
            $this->addFlash('error', 'Signature manquante, veuillez signer avant de valider.');

            return $this->redirectToRoute('public_attestation_cle_show', ['uuid' => $uuid]);
        }

        $this->formService->submit($attestation, $data);

        return $this->redirectToRoute('public_attestation_cle_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function confirmation(string $uuid): Response
    {
        $attestation = $this->trouver($uuid);

        if ($attestation === null || !$attestation->estSignee()) {
            return $this->lienInvalide();
        }

        return $this->render('public/attestation_cle/confirmation.html.twig', [
            'attestation' => $attestation,
            'detenteur' => $attestation->getDetenteur(),
            'season' => $attestation->getSeason(),
        ]);
    }

    private function trouver(string $uuid): ?AttestationCle
    {
        return Uuid::isValid($uuid)
            ? $this->attestationRepo->findByUuid(Uuid::fromString($uuid))
            : null;
    }

    private function lienInvalide(): Response
    {
        return $this->render('public/lien_expire.html.twig', [
            'message' => 'Ce lien de signature n\'est plus valide.',
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

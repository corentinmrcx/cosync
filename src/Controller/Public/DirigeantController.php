<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\DirigeantPublicFormData;
use App\Entity\Dirigeant;
use App\Repository\DirigeantRepository;
use App\Service\DirigeantFormService;
use App\Service\Form\AttestationTransportRequestFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/dirigeant', name: 'public_dirigeant_')]
class DirigeantController extends AbstractController
{
    public function __construct(
        private readonly AttestationTransportRequestFactory $attestationFactory,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, DirigeantRepository $dirigeantRepo): Response
    {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        if ($dirigeant->isPublicFormComplete()) {
            return $this->redirectToRoute('public_dirigeant_confirmation', ['uuid' => $uuid]);
        }

        if (!$dirigeant->isFormTokenValid()) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        return $this->render('public/dirigeant/form.html.twig', [
            'dirigeant'     => $dirigeant,
            'needTaille'    => $dirigeant->getLicencie() === null && $dirigeant->getTailleHaut() === null,
            'needPhoto'     => $dirigeant->getLicencie() === null && $dirigeant->getAutorisationPhoto() === null,
            'needTransport' => $dirigeant->getVolontaireTransport() === null,
            'needReglement' => $dirigeant->needsReglementSignature(),
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'])]
    public function submit(
        string $uuid,
        Request $request,
        DirigeantRepository $dirigeantRepo,
        DirigeantFormService $formService,
    ): Response {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || !$dirigeant->isFormTokenValid()) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('dirigeant_submit', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('public_dirigeant_show', ['uuid' => $uuid]);
        }

        $data = $this->buildFormData($request, $dirigeant);

        if ($data === null) {
            $this->addFlash('error', 'Formulaire incomplet, veuillez remplir tous les champs.');
            return $this->redirectToRoute('public_dirigeant_show', ['uuid' => $uuid]);
        }

        $formService->submit($dirigeant, $data);

        return $this->redirectToRoute('public_dirigeant_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'])]
    public function confirmation(string $uuid, DirigeantRepository $dirigeantRepo): Response
    {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || $dirigeant->getFormCompletedAt() === null) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        return $this->render('public/dirigeant/confirmation.html.twig', [
            'dirigeant' => $dirigeant,
        ]);
    }

    private function buildFormData(Request $request, Dirigeant $dirigeant): ?DirigeantPublicFormData
    {
        // Flags recalculés côté serveur (jamais à partir du client)
        $needTaille    = $dirigeant->getLicencie() === null && $dirigeant->getTailleHaut() === null;
        $needPhoto     = $dirigeant->getLicencie() === null && $dirigeant->getAutorisationPhoto() === null;
        $needTransport = $dirigeant->getVolontaireTransport() === null;
        $needReglement = $dirigeant->needsReglementSignature();

        $tailleHaut = null;
        $tailleBas  = null;
        $pointure   = null;

        if ($needTaille) {
            $tailleHaut = $request->request->get('taille_haut', '');
            $tailleBas  = $request->request->get('taille_bas', '');
            $pointure   = $request->request->get('pointure', '');

            if ($tailleHaut === '' || $tailleBas === '' || $pointure === '') {
                return null;
            }
        }

        $autorisationPhoto = null;

        if ($needPhoto) {
            $photoRaw = $request->request->get('autorisation_photo');
            if ($photoRaw === null) {
                return null;
            }
            $autorisationPhoto = $photoRaw === '1';
        }

        // Transport : collecté uniquement s'il n'est pas déjà renseigné.
        // Sinon on conserve la valeur existante (cas d'une simple complétion,
        // ex. l'ajout du règlement intérieur sur un dossier déjà rempli).
        $volontaireTransport = $dirigeant->getVolontaireTransport() ?? false;
        $attestationData     = null;

        if ($needTransport) {
            $volRaw = $request->request->get('volontaire_transport');
            if ($volRaw === null) {
                return null;
            }
            $volontaireTransport = $volRaw === '1';

            if ($volontaireTransport) {
                $attestationData = $this->attestationFactory->fromRequest($request);
                if ($attestationData === null) {
                    return null;
                }
            }
        }

        // Signature du règlement intérieur : requise sauf si déjà signé
        $reglementSignature = null;

        if ($needReglement) {
            $signatureData = $request->request->get('signature_data', '');

            if ($signatureData === ''
                || !str_starts_with($signatureData, 'data:image/')
                || strlen($signatureData) > 2_800_000) {
                return null;
            }

            $reglementSignature = $signatureData;
        }

        return new DirigeantPublicFormData(
            tailleHaut:             $tailleHaut,
            tailleBas:              $tailleBas,
            pointure:               $pointure,
            autorisationPhoto:      $autorisationPhoto,
            volontaireTransport:    $volontaireTransport,
            attestationTransport:   $attestationData,
            reglementSignatureData: $reglementSignature,
        );
    }

}

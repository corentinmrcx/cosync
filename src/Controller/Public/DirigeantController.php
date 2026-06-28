<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AttestationTransportData;
use App\DTO\DirigeantPublicFormData;
use App\Entity\Dirigeant;
use App\Repository\DirigeantRepository;
use App\Service\DirigeantFormService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/dirigeant', name: 'public_dirigeant_')]
class DirigeantController extends AbstractController
{
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

        // Le transport doit déjà être renseigné si non demandé (sécurité)
        if (!$needTransport) {
            return null;
        }

        $volRaw = $request->request->get('volontaire_transport');
        if ($volRaw === null) {
            return null;
        }
        $volontaireTransport = $volRaw === '1';

        $attestationData = null;

        if ($volontaireTransport) {
            $attestationData = $this->buildAttestationData($request);
            if ($attestationData === null) {
                return null;
            }
        }

        return new DirigeantPublicFormData(
            tailleHaut:           $tailleHaut,
            tailleBas:            $tailleBas,
            pointure:             $pointure,
            autorisationPhoto:    $autorisationPhoto,
            volontaireTransport:  $volontaireTransport,
            attestationTransport: $attestationData,
        );
    }

    private function buildAttestationData(Request $request): ?AttestationTransportData
    {
        $nomConducteur    = trim($request->request->get('attestation_nom_conducteur', ''));
        $prenomConducteur = trim($request->request->get('attestation_prenom_conducteur', ''));
        $numPermis        = $request->request->get('attestation_num_permis', '');
        $assurance        = $request->request->get('attestation_assurance', '');
        $dateCTRaw        = $request->request->get('attestation_date_ct', '');
        $sigAttest        = $request->request->get('attestation_signature_data', '');
        $engagement       = $request->request->get('attestation_engagement') === '1';

        if ($nomConducteur === '' || $prenomConducteur === ''
            || $numPermis === '' || $assurance === '' || $dateCTRaw === '' || $sigAttest === '') {
            return null;
        }

        if (!str_starts_with($sigAttest, 'data:image/') || strlen($sigAttest) > 2_800_000) {
            return null;
        }

        try {
            $dateCT = new \DateTimeImmutable($dateCTRaw);
        } catch (\Exception) {
            return null;
        }

        // Refuser une date de contrôle technique dans le futur
        if ($dateCT > new \DateTimeImmutable('today')) {
            return null;
        }

        return new AttestationTransportData(
            nomConducteur:       $nomConducteur,
            prenomConducteur:    $prenomConducteur,
            numPermis:           $numPermis,
            assuranceNomAdresse: $assurance,
            dateCT:              $dateCT,
            engagementPris:      $engagement,
            signatureData:       $sigAttest,
        );
    }
}

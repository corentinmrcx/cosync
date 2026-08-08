<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\DirigeantPublicFormData;
use App\Entity\Dirigeant;
use App\Repository\DirigeantRepository;
use App\Service\DirigeantDossierCompletion;
use App\Service\DirigeantFormService;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Form\AttestationTransportRequestFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/dirigeant', name: 'public_dirigeant_')]
class DirigeantController extends AbstractController
{
    /** Un PNG de signature dépasse rarement 2 Mo une fois encodé en base64 ; au-delà, soumission suspecte. */
    private const SIGNATURE_MAX_LENGTH = 2_800_000;

    public function __construct(
        private readonly AttestationTransportRequestFactory $attestationFactory,
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly DirigeantDossierCompletion $dossierCompletion,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(string $uuid, DirigeantRepository $dirigeantRepo): Response
    {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        if ($this->dossierCompletion->isComplete($dirigeant)) {
            return $this->redirectToRoute('public_dirigeant_confirmation', ['uuid' => $uuid]);
        }

        if (!$dirigeant->isFormTokenValid()) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        return $this->render('public/dirigeant/form.html.twig', [
            'dirigeant' => $dirigeant,
            'needTaille' => $dirigeant->getLicencie() === null && $dirigeant->getTailleHaut() === null,
            'needPhoto' => $dirigeant->getLicencie() === null && $dirigeant->getAutorisationPhoto() === null,
            'needTransport' => $dirigeant->getVolontaireTransport() === null,
            'documents' => $this->documentResolver->manquantsPourDirigeant($dirigeant),
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
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

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
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
        $needTaille = $dirigeant->getLicencie() === null && $dirigeant->getTailleHaut() === null;
        $needPhoto = $dirigeant->getLicencie() === null && $dirigeant->getAutorisationPhoto() === null;
        $needTransport = $dirigeant->getVolontaireTransport() === null;

        $tailleHaut = null;
        $tailleBas = null;
        $pointure = null;

        if ($needTaille) {
            $tailleHaut = $request->request->get('taille_haut', '');
            $tailleBas = $request->request->get('taille_bas', '');
            $pointure = $request->request->get('pointure', '');

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
        // ex. l'ajout d'une charte sur un dossier déjà rempli).
        $volontaireTransport = $dirigeant->getVolontaireTransport() ?? false;
        $attestationData = null;

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

        $documentSignatures = $this->collectDocumentSignatures($request, $dirigeant);

        if ($documentSignatures === null) {
            return null;
        }

        return new DirigeantPublicFormData(
            tailleHaut: $tailleHaut,
            tailleBas: $tailleBas,
            pointure: $pointure,
            autorisationPhoto: $autorisationPhoto,
            volontaireTransport: $volontaireTransport,
            attestationTransport: $attestationData,
            documentSignatures: $documentSignatures,
        );
    }

    /**
     * Une signature par document réellement attendu. La liste des documents est
     * recalculée côté serveur : un id envoyé par le client mais non attendu est
     * ignoré, et il manque une signature attendue, la soumission est rejetée.
     *
     * @return array<int, string>|null null si une signature attendue manque ou est invalide
     */
    private function collectDocumentSignatures(Request $request, Dirigeant $dirigeant): ?array
    {
        // Lecture défensive : une valeur scalaire doit être rejetée comme signature
        // manquante, pas provoquer une réponse 400 incompréhensible pour le signataire.
        $brut = $request->request->all()['signature_data'] ?? null;
        $soumises = is_array($brut) ? $brut : [];
        $retenues = [];

        foreach ($this->documentResolver->manquantsPourDirigeant($dirigeant) as $document) {
            $signature = $soumises[$document->getId()] ?? null;

            if (!$this->isSignatureValide($signature)) {
                return null;
            }

            $retenues[$document->getId()] = $signature;
        }

        return $retenues;
    }

    private function isSignatureValide(mixed $signature): bool
    {
        return is_string($signature)
            && $signature !== ''
            && str_starts_with($signature, 'data:image/')
            && strlen($signature) <= self::SIGNATURE_MAX_LENGTH;
    }
}

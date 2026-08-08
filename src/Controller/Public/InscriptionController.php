<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AutorisationCompletionData;
use App\DTO\DotationChoixData;
use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Service\CotisationResolver;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Form\AttestationTransportRequestFactory;
use App\Service\Form\AutorisationCompletionService;
use App\Service\Form\DotationChoixRequestFactory;
use App\Service\Form\InscriptionFormService;
use App\Service\Stock\DotationModeleService;
use App\Service\Stock\DotationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/inscription', name: 'public_inscription_')]
class InscriptionController extends AbstractController
{
    /** Une signature manuscrite dépasse rarement 300 Ko ; au-delà, c'est une soumission suspecte. */
    private const SIGNATURE_MAX_LENGTH = 2_800_000;

    public function __construct(
        private readonly AttestationTransportRequestFactory $attestationFactory,
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, LicencieRepository $licencieRepo, DotationResolver $resolver, CotisationResolver $cotisationResolver): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $dossier = $licencie->getDossierClub();

        // Formulaire déjà soumis → rediriger vers la confirmation
        if ($dossier !== null && $dossier->getFormCompletedAt() !== null) {
            return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
        }

        if (!$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        return $this->render('public/inscription/form.html.twig', [
            'licencie'        => $licencie,
            'montant'         => $cotisationResolver->resolve($licencie),
            'dotationGroupes' => $resolver->getChoiceGroups($licencie),
            // Personnalisations dues sans qu'aucune question de choix ne soit posée :
            // groupe à option unique (nouveau licencié) ou article fixe personnalisé.
            'dotationAutos'   => $resolver->getAutoPersonnalisationRequests($licencie),
            'personnalisationMaxDefaut' => DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
            'documents'       => $this->documentResolver->manquantsPourLicencie($licencie),
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'])]
    public function submit(string $uuid, Request $request, LicencieRepository $licencieRepo, InscriptionFormService $formService, DotationChoixRequestFactory $dotationFactory): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('inscription_submit', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $dotation = $dotationFactory->fromRequest($request, $licencie);
        if ($dotation === null) {
            $this->addFlash('error', 'Vérifiez votre choix de dotation et le texte à personnaliser, puis confirmez son orthographe.');
            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $data = $this->buildFormData($request, $licencie, $licencie->getCategory()->isJeune(), $dotation);

        if ($data === null) {
            $this->addFlash('error', 'Formulaire incomplet, veuillez remplir tous les champs.');
            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $formService->submit($licencie, $data);

        // Paiement par carte : l'inscription est enregistrée d'abord (PDF, signature, Drive),
        // le licencié ne peut donc rien perdre s'il abandonne sur HelloAsso.
        if ($request->request->get('pay_online') === '1') {
            return $this->redirectToRoute('public_helloasso_checkout_start', ['uuid' => $uuid]);
        }

        return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'])]
    public function confirmation(
        string $uuid,
        LicencieRepository $licencieRepo,
        CotisationResolver $cotisationResolver,
        TransactionRepository $transactionRepo,
    ): Response {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || $licencie->getDossierClub() === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $montant = $cotisationResolver->resolve($licencie);

        return $this->render('public/inscription/confirmation.html.twig', [
            'licencie' => $licencie,
            'dossier'  => $licencie->getDossierClub(),
            'montant'  => $montant,
            // Seule une transaction réellement enregistrée autorise à annoncer un paiement reçu.
            'paiementRecu' => $transactionRepo->sumByLicencieAndSeason($licencie, $licencie->getSeason()) >= (float) $montant,
        ]);
    }

    private function buildFormData(Request $request, Licencie $licencie, bool $isJeune, DotationChoixData $dotation): ?InscriptionFormData
    {
        $tailleHaut = $request->request->get('taille_haut', '');
        $tailleBas  = $request->request->get('taille_bas', '');
        $pointure   = $request->request->get('pointure', '');
        $photoRaw   = $request->request->get('autorisation_photo');

        if ($tailleHaut === '' || $tailleBas === '' || $pointure === '' || $photoRaw === null) {
            return null;
        }

        $documentSignatures = $this->collectDocumentSignatures($request, $licencie);

        if ($documentSignatures === null) {
            return null;
        }

        $multiPayment = $request->request->get('multi_payment') === '1';

        if ($request->request->get('pay_online') === '1') {
            // Le bouton « payer par carte » vaut choix du mode : aucun radio n'est coché.
            $modes = [PaymentMode::CB_ONLINE];
        } elseif ($multiPayment) {
            $rawModes = (array) ($request->request->all()['payment_intentions'] ?? []);
            $modes    = [];
            foreach ($rawModes as $raw) {
                $m = PaymentMode::tryFrom((string) $raw);
                if ($m === null) {
                    return null;
                }
                $modes[] = $m;
            }
            if (count($modes) === 0) {
                return null;
            }
        } else {
            $rawMode = $request->request->get('payment_intention', '');
            $single  = PaymentMode::tryFrom($rawMode);
            if ($single === null) {
                return null;
            }
            $modes = [$single];
        }

        $transportDirig     = null;
        $transportParent    = null;
        $autorisationAccident = null;
        $volontaireTransport  = null;
        $attestationData      = null;

        if ($isJeune) {
            $dirigRaw    = $request->request->get('autorisation_transport_dirigeants');
            $parentRaw   = $request->request->get('autorisation_transport_parents');
            $accidentRaw = $request->request->get('autorisation_accident');
            $volRaw      = $request->request->get('volontaire_transport');

            if ($dirigRaw === null || $parentRaw === null || $accidentRaw === null || $volRaw === null) {
                return null;
            }

            $transportDirig      = $dirigRaw === '1';
            $transportParent     = $parentRaw === '1';
            $autorisationAccident = $accidentRaw === '1';
            $volontaireTransport  = $volRaw === '1';

            if ($volontaireTransport) {
                $attestationData = $this->attestationFactory->fromRequest($request);
                if ($attestationData === null) {
                    return null;
                }
            }
        }

        return new InscriptionFormData(
            tailleHaut:                      $tailleHaut,
            tailleBas:                       $tailleBas,
            pointure:                        $pointure,
            autorisationPhoto:               $photoRaw === '1',
            autorisationTransportDirigeants: $transportDirig,
            autorisationTransportParents:    $transportParent,
            autorisationAccident:            $autorisationAccident,
            volontaireTransport:             $volontaireTransport,
            documentSignatures:              $documentSignatures,
            paymentIntentions:               $modes,
            attestationTransport:            $attestationData,
            dotationChoix:                   $dotation->choix,
            dotationPersonnalisation:        $dotation->personnalisation,
        );
    }

    /**
     * Une signature par document réellement attendu. La liste est recalculée côté
     * serveur : un id envoyé par le client mais non attendu est ignoré, et s'il manque
     * une signature attendue, la soumission est rejetée.
     *
     * @return array<int, string>|null null si une signature attendue manque ou est invalide
     */
    private function collectDocumentSignatures(Request $request, Licencie $licencie): ?array
    {
        // Lecture défensive : une valeur scalaire doit être rejetée comme signature
        // manquante, pas provoquer une réponse 400 incompréhensible pour le licencié.
        $brut     = $request->request->all()['signature_data'] ?? null;
        $soumises = is_array($brut) ? $brut : [];
        $retenues = [];

        foreach ($this->documentResolver->manquantsPourLicencie($licencie) as $document) {
            $signature = $soumises[$document->getId()] ?? null;

            if (!is_string($signature)
                || $signature === ''
                || !str_starts_with($signature, 'data:image/')
                || strlen($signature) > self::SIGNATURE_MAX_LENGTH) {
                return null;
            }

            $retenues[$document->getId()] = $signature;
        }

        return $retenues;
    }

    #[Route('/{uuid}/completer', name: 'completer', methods: ['GET'])]
    public function completer(string $uuid, LicencieRepository $licencieRepo, AutorisationCompletionService $completionService): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $manquants = $completionService->missingKeys($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        return $this->render('public/inscription/completer.html.twig', [
            'licencie'  => $licencie,
            'manquants' => $manquants,
            'isJeune'   => $licencie->getCategory()->isJeune(),
        ]);
    }

    #[Route('/{uuid}/completer', name: 'completer_submit', methods: ['POST'])]
    public function completerSubmit(string $uuid, Request $request, LicencieRepository $licencieRepo, AutorisationCompletionService $completionService): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('inscription_completer', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('public_inscription_completer', ['uuid' => $uuid]);
        }

        $manquants = $completionService->missingKeys($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        $data = $this->buildCompletionData($request, $manquants);
        if ($data === null) {
            $this->addFlash('error', 'Merci de répondre à toutes les questions.');
            return $this->redirectToRoute('public_inscription_completer', ['uuid' => $uuid]);
        }

        $completionService->apply($licencie, $data);

        return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => false]);
    }

    /**
     * Construit les réponses de complétion à partir des seules autorisations demandées.
     * Null si une réponse attendue manque.
     *
     * @param string[] $manquants
     */
    private function buildCompletionData(Request $request, array $manquants): ?AutorisationCompletionData
    {
        $bool = static fn (?string $raw): ?bool => $raw === '1' ? true : ($raw === '0' ? false : null);

        $photo = $accident = $dirig = $parent = $vol = null;
        $attestation = null;

        if (in_array('photo', $manquants, true)) {
            $photo = $bool($request->request->get('autorisation_photo'));
            if ($photo === null) {
                return null;
            }
        }
        if (in_array('accident', $manquants, true)) {
            $accident = $bool($request->request->get('autorisation_accident'));
            if ($accident === null) {
                return null;
            }
        }
        if (in_array('transport_dirigeants', $manquants, true)) {
            $dirig = $bool($request->request->get('autorisation_transport_dirigeants'));
            if ($dirig === null) {
                return null;
            }
        }
        if (in_array('transport_parents', $manquants, true)) {
            $parent = $bool($request->request->get('autorisation_transport_parents'));
            if ($parent === null) {
                return null;
            }
        }
        if (in_array('volontaire', $manquants, true)) {
            $vol = $bool($request->request->get('volontaire_transport'));
            if ($vol === null) {
                return null;
            }
            if ($vol === true) {
                $attestation = $this->attestationFactory->fromRequest($request);
                if ($attestation === null) {
                    return null;
                }
            }
        }

        return new AutorisationCompletionData($photo, $accident, $dirig, $parent, $vol, $attestation);
    }
}

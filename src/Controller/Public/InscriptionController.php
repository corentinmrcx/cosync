<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AutorisationCompletionData;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Security\CsrfGuard;
use App\Service\CotisationResolver;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Form\AttestationTransportRequestFactory;
use App\Service\Form\AutorisationCompletionService;
use App\Service\Form\DotationChoixRequestFactory;
use App\Service\Form\InscriptionFormRequestFactory;
use App\Service\Form\InscriptionFormService;
use App\Service\Stock\DotationModeleService;
use App\Service\Stock\DotationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/inscription', name: 'public_inscription_')]
class InscriptionController extends AbstractController
{
    /** Un PNG de signature dépasse rarement 2 Mo une fois encodé en base64 ; au-delà, soumission suspecte. */
    public function __construct(
        private readonly LicencieRepository $licencieRepo,
        private readonly DotationResolver $resolver,
        private readonly CotisationResolver $cotisationResolver,
        private readonly InscriptionFormService $formService,
        private readonly DotationChoixRequestFactory $dotationFactory,
        private readonly TransactionRepository $transactionRepo,
        private readonly AutorisationCompletionService $completionService,
        private readonly CsrfGuard $csrf,
        private readonly InscriptionFormRequestFactory $formFactory,
        private readonly AttestationTransportRequestFactory $attestationFactory,
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(string $uuid): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

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
            'licencie' => $licencie,
            'montant' => $this->cotisationResolver->resolve($licencie),
            'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
            'dotationGroupes' => $this->resolver->getChoiceGroups($licencie),
            // Personnalisations dues sans qu'aucune question de choix ne soit posée :
            // groupe à option unique (nouveau licencié) ou article fixe personnalisé.
            'dotationAutos' => $this->resolver->getAutoPersonnalisationRequests($licencie),
            'personnalisationMaxDefaut' => DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
            'documents' => $this->documentResolver->manquantsPourLicencie($licencie),
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function submit(string $uuid, Request $request): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $this->csrf->valider('inscription_submit', $request);

        $dotation = $this->dotationFactory->fromRequest($request, $licencie);
        if ($dotation === null) {
            $this->addFlash('error', 'Vérifiez votre choix de dotation et le texte à personnaliser, puis confirmez son orthographe.');

            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $data = $this->formFactory->fromRequest($request, $licencie, $licencie->getCategory()->isJeune(), $dotation);

        if ($data === null) {
            $this->addFlash('error', 'Formulaire incomplet, veuillez remplir tous les champs.');

            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $this->formService->submit($licencie, $data);

        // Paiement par carte : l'inscription est enregistrée d'abord (PDF, signature, Drive),
        // le licencié ne peut donc rien perdre s'il abandonne sur HelloAsso.
        if ($request->request->get('pay_online') === '1') {
            return $this->redirectToRoute('public_helloasso_checkout_start', ['uuid' => $uuid]);
        }

        return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function confirmation(
        string $uuid,
    ): Response {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || $licencie->getDossierClub() === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $montant = $this->cotisationResolver->resolve($licencie);

        return $this->render('public/inscription/confirmation.html.twig', [
            'licencie' => $licencie,
            'dossier' => $licencie->getDossierClub(),
            'montant' => $montant,
            'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
            // Seule une transaction réellement enregistrée autorise à annoncer un paiement reçu.
            'paiementRecu' => $this->transactionRepo->sumByLicencieAndSeason($licencie, $licencie->getSeason()) >= (float) $montant,
        ]);
    }

    #[Route('/{uuid}/completer', name: 'completer', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function completer(string $uuid): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $manquants = $this->completionService->missingKeys($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        return $this->render('public/inscription/completer.html.twig', [
            'licencie' => $licencie,
            'manquants' => $manquants,
            'isJeune' => $licencie->getCategory()->isJeune(),
        ]);
    }

    #[Route('/{uuid}/completer', name: 'completer_submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function completerSubmit(string $uuid, Request $request): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $this->csrf->valider('inscription_completer', $request);

        $manquants = $this->completionService->missingKeys($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        $data = $this->buildCompletionData($request, $manquants);
        if ($data === null) {
            $this->addFlash('error', 'Merci de répondre à toutes les questions.');

            return $this->redirectToRoute('public_inscription_completer', ['uuid' => $uuid]);
        }

        $this->completionService->apply($licencie, $data);

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

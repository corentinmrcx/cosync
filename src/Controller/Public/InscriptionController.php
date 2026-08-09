<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\AutorisationManquante;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Security\CsrfGuard;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Dotation\DotationChoixRequestFactory;
use App\Service\Dotation\DotationModeleService;
use App\Service\Dotation\DotationResolver;
use App\Service\Inscription\AutorisationCompletionRequestFactory;
use App\Service\Inscription\AutorisationCompletionService;
use App\Service\Inscription\InscriptionFormRequestFactory;
use App\Service\Inscription\InscriptionFormService;
use App\Service\Payment\CotisationResolver;
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
        private readonly AutorisationCompletionRequestFactory $completionFactory,
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    #[Route('/{uuid}', name: 'show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(string $uuid): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
        }

        $dossier = $licencie->getDossierClub();

        // Formulaire déjà soumis → rediriger vers la confirmation
        if ($dossier !== null && $dossier->getFormCompletedAt() !== null) {
            return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
        }

        if (!$licencie->isFormTokenValid()) {
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
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
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
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
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
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
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
        }

        $manquants = $this->completionService->manquantes($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        return $this->render('public/inscription/completer.html.twig', [
            'licencie' => $licencie,
            'manquants' => AutorisationManquante::valeurs($manquants),
            'isJeune' => $licencie->getCategory()->isJeune(),
        ]);
    }

    #[Route('/{uuid}/completer', name: 'completer_submit', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function completerSubmit(string $uuid, Request $request): Response
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/lien_expire.html.twig', ['message' => 'Ce lien d\'inscription n\'est plus valide.']);
        }

        $this->csrf->valider('inscription_completer', $request);

        $manquants = $this->completionService->manquantes($licencie);
        if ($manquants === []) {
            return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => true]);
        }

        $data = $this->completionFactory->fromRequest($request, $manquants);
        if ($data === null) {
            $this->addFlash('error', 'Merci de répondre à toutes les questions.');

            return $this->redirectToRoute('public_inscription_completer', ['uuid' => $uuid]);
        }

        $this->completionService->apply($licencie, $data);

        return $this->render('public/inscription/completer_done.html.twig', ['rienAFaire' => false]);
    }
}

<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\DocumentSignableData;
use App\Entity\DocumentSignable;
use App\Enum\DirigeantRole;
use App\Enum\DocumentCible;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignableRepository;
use App\Repository\DocumentSignatureRepository;
use App\Repository\LicencieRepository;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Document\DocumentSignableService;
use App\Service\Mail\DirigeantLinkService;
use App\Service\Pdf\PdfGeneratorService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration des documents que le club fait signer.
 *
 * Remplace les deux écrans d'édition de règlement, un par destinataire : le nombre de
 * documents n'est plus une constante du code, donc l'écran non plus.
 */
#[Route('/admin/config/documents', name: 'admin_documents_')]
class DocumentSignableController extends AbstractController
{
    public function __construct(
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignableService $documentService,
        private readonly DocumentRequirementResolver $resolver,
        private readonly SeasonContext $seasonContext,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(DocumentSignatureRepository $signatureRepo, LicencieRepository $licencieRepo): Response
    {
        $season = $this->seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $documents = $this->documentRepo->findBySeason($season);

        // Combien de personnes n'ont pas encore signé. Côté dirigeants, le ciblage
        // restreint la population et impose de la parcourir ; côté licenciés, le
        // document s'adresse à toute la saison, une soustraction suffit.
        $licenciesDeLaSaison = $licencieRepo->count(['season' => $season]);

        $stats = [];
        foreach ($documents as $document) {
            $signes = $signatureRepo->countByDocument($document);

            $stats[$document->getId()] = [
                'signes'    => $signes,
                'concernes' => $document->getCible() === DocumentCible::DIRIGEANT
                    ? null
                    : $licenciesDeLaSaison,
                'enAttente' => $document->getCible() === DocumentCible::DIRIGEANT
                    ? count($this->resolver->dirigeantsEnAttente($document))
                    : max(0, $licenciesDeLaSaison - $signes),
            ];
        }

        return $this->render('admin/documents/list.html.twig', [
            'season'    => $season,
            'documents' => $documents,
            'stats'     => $stats,
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, DirigeantRepository $dirigeantRepo): Response
    {
        $season = $this->seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('document_new', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_documents_new');
            }

            try {
                $document = $this->documentService->creer($this->buildData($request), $season);
                $this->addFlash('success', sprintf('Document « %s » créé.', $document->getTitre()));

                return $this->redirectToRoute('admin_documents_list');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/documents/form.html.twig', [
            'document'   => null,
            'season'     => $season,
            'formAction' => $this->generateUrl('admin_documents_new'),
            'csrfId'     => 'document_new',
            'apercuUrl'  => null,
            'roles'      => DirigeantRole::cases(),
            'dirigeants' => $dirigeantRepo->findBySeason($season),
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(DocumentSignable $document, Request $request, DirigeantRepository $dirigeantRepo): Response
    {
        $csrfId = 'document_edit_' . $document->getId();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid($csrfId, $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_documents_edit', ['id' => $document->getId()]);
            }

            $this->documentService->mettreAJour($document, $this->buildData($request));
            $this->addFlash('success', sprintf('Document « %s » mis à jour.', $document->getTitre()));

            return $this->redirectToRoute('admin_documents_list');
        }

        return $this->render('admin/documents/form.html.twig', [
            'document'   => $document,
            'season'     => $document->getSeason(),
            'formAction' => $this->generateUrl('admin_documents_edit', ['id' => $document->getId()]),
            'csrfId'     => $csrfId,
            'apercuUrl'  => $this->generateUrl('admin_documents_apercu', ['id' => $document->getId()]),
            'roles'      => DirigeantRole::cases(),
            'dirigeants' => $dirigeantRepo->findBySeason($document->getSeason()),
        ]);
    }

    #[Route('/{id}/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(DocumentSignable $document, PdfGeneratorService $pdfGenerator): Response
    {
        return new Response($pdfGenerator->generatePreview($document), Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf(
                'inline; filename="apercu-%s-%s.pdf"',
                $document->getCode(),
                $document->getSeason()->getLabel(),
            ),
        ]);
    }

    #[Route('/{id}/activation', name: 'toggle', methods: ['POST'])]
    public function toggle(DocumentSignable $document, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('document_toggle_' . $document->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_documents_list');
        }

        $this->documentService->basculerActivation($document);
        $this->addFlash('success', sprintf(
            'Document « %s » %s.',
            $document->getTitre(),
            $document->isActif() ? 'réactivé' : 'désactivé',
        ));

        return $this->redirectToRoute('admin_documents_list');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(DocumentSignable $document, Request $request, DocumentSignatureRepository $signatureRepo): Response
    {
        if (!$this->isCsrfTokenValid('document_delete_' . $document->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_documents_list');
        }

        // Supprimer emporterait les signatures recueillies : on impose la désactivation,
        // qui retire le document du parcours sans effacer ce qui a été signé.
        $signatures = $signatureRepo->countByDocument($document);

        if ($signatures > 0) {
            $this->addFlash('error', sprintf(
                'Impossible de supprimer « %s » : %d signature(s) y sont rattachées. Désactivez-le plutôt.',
                $document->getTitre(),
                $signatures,
            ));

            return $this->redirectToRoute('admin_documents_list');
        }

        $titre = $document->getTitre();
        $this->documentService->supprimer($document);
        $this->addFlash('success', sprintf('Document « %s » supprimé.', $titre));

        return $this->redirectToRoute('admin_documents_list');
    }

    /**
     * Relance groupée : un document ajouté en cours de saison ne se voit pas, les
     * dossiers concernés étant déjà complets et leurs liens consommés. L'écran liste
     * qui est concerné avant tout envoi.
     */
    #[Route('/{id}/relancer', name: 'relancer', methods: ['GET', 'POST'])]
    public function relancer(DocumentSignable $document, Request $request, DirigeantLinkService $linkService): Response
    {
        if ($document->getCible() !== DocumentCible::DIRIGEANT) {
            $this->addFlash('error', 'La relance groupée ne concerne que les documents destinés aux dirigeants.');
            return $this->redirectToRoute('admin_documents_list');
        }

        $enAttente = $this->resolver->dirigeantsEnAttente($document);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('document_relancer_' . $document->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_documents_relancer', ['id' => $document->getId()]);
            }

            $envoyes = 0;
            $sansEmail = 0;

            foreach ($enAttente as $dirigeant) {
                if ($dirigeant->getEmail() === null) {
                    $sansEmail++;
                    continue;
                }

                $linkService->send($dirigeant);
                $envoyes++;
            }

            $this->addFlash('success', sprintf(
                '%d lien(s) envoyé(s)%s.',
                $envoyes,
                $sansEmail > 0 ? sprintf(', %d dirigeant(s) sans adresse email', $sansEmail) : '',
            ));

            return $this->redirectToRoute('admin_documents_list');
        }

        return $this->render('admin/documents/relancer.html.twig', [
            'document'  => $document,
            'enAttente' => $enAttente,
        ]);
    }

    private function buildData(Request $request): DocumentSignableData
    {
        $cible = DocumentCible::tryFrom((string) $request->request->get('cible')) ?? DocumentCible::DIRIGEANT;

        $roles = array_values(array_filter(array_map(
            static fn (string $value) => DirigeantRole::tryFrom($value),
            array_map('strval', $request->request->all('roles')),
        )));

        return new DocumentSignableData(
            titre:       trim((string) $request->request->get('titre', '')),
            libelle:     trim((string) $request->request->get('libelle', '')),
            contenuHtml: $request->request->get('contenu_html') ?: null,
            cible:       $cible,
            roles:       $roles,
            dirigeants:  array_map('strval', $request->request->all('dirigeants')),
            actif:       $request->request->get('actif') === '1',
        );
    }
}

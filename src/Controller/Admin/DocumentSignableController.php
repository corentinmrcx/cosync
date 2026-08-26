<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DocumentSignableData;
use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Enum\DocumentCible;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignableRepository;
use App\Security\CsrfGuard;
use App\Service\Document\DocumentSignableService;
use App\Service\Pdf\PdfGeneratorService;
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
#[Route('/admin/effectif/documents', name: 'admin_documents_')]
class DocumentSignableController extends AbstractController
{
    public function __construct(
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly CsrfGuard $csrf,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignableService $documentService,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[CurrentSeason] Season $season): Response
    {
        $documents = $this->documentRepo->findBySeason($season);

        return $this->render('admin/documents/list.html.twig', [
            'documents' => $documents,
            'stats' => $this->documentService->statistiques($documents, $season),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentSeason] Season $season): Response
    {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('document_new', $request);

            try {
                $document = $this->documentService->creer($this->buildData($request), $season);
                $this->addFlash('success', sprintf('Document « %s » créé.', $document->getTitre()));

                return $this->redirectToRoute('admin_documents_list');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/documents/form.html.twig', [
            'document' => null,
            'season' => $season,
            'formAction' => $this->generateUrl('admin_documents_new'),
            'csrfId' => 'document_new',
            'apercuUrl' => null,
            'roles' => DirigeantRole::cases(),
            'dirigeants' => $this->dirigeantRepo->findBySeason($season),
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(DocumentSignable $document, Request $request): Response
    {
        $csrfId = 'document_edit_' . $document->getId();

        if ($request->isMethod('POST')) {
            $this->csrf->valider($csrfId, $request);

            $this->documentService->mettreAJour($document, $this->buildData($request));
            $this->addFlash('success', sprintf('Document « %s » mis à jour.', $document->getTitre()));

            return $this->redirectToRoute('admin_documents_list');
        }

        return $this->render('admin/documents/form.html.twig', [
            'document' => $document,
            'season' => $document->getSeason(),
            'formAction' => $this->generateUrl('admin_documents_edit', ['id' => $document->getId()]),
            'csrfId' => $csrfId,
            'apercuUrl' => $this->generateUrl('admin_documents_apercu', ['id' => $document->getId()]),
            'roles' => DirigeantRole::cases(),
            'dirigeants' => $this->dirigeantRepo->findBySeason($document->getSeason()),
        ]);
    }

    #[Route('/{id}/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(DocumentSignable $document): Response
    {
        return new Response($this->pdfGenerator->generatePreview($document), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
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
        $this->csrf->valider('document_toggle_' . $document->getId(), $request);

        $this->documentService->basculerActivation($document);
        $this->addFlash('success', sprintf(
            'Document « %s » %s.',
            $document->getTitre(),
            $document->isActif() ? 'réactivé' : 'désactivé',
        ));

        return $this->redirectToRoute('admin_documents_list');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(DocumentSignable $document, Request $request): Response
    {
        $this->csrf->valider('document_delete_' . $document->getId(), $request);

        $titre = $document->getTitre();

        try {
            $this->documentService->supprimer($document);
            $this->addFlash('success', sprintf('Document « %s » supprimé.', $titre));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_documents_list');
    }

    private function buildData(Request $request): DocumentSignableData
    {
        $cible = DocumentCible::tryFrom((string) $request->request->get('cible')) ?? DocumentCible::DIRIGEANT;

        $roles = array_values(array_filter(array_map(
            static fn (string $value) => DirigeantRole::tryFrom($value),
            array_map('strval', $request->request->all('roles')),
        )));

        return new DocumentSignableData(
            titre: trim((string) $request->request->get('titre', '')),
            libelle: trim((string) $request->request->get('libelle', '')),
            contenuHtml: $request->request->get('contenu_html') ?: null,
            cible: $cible,
            roles: $roles,
            dirigeants: array_map('strval', $request->request->all('dirigeants')),
            actif: $request->request->get('actif') === '1',
        );
    }
}
